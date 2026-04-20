const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const runtimePath = path.join(__dirname, '..', 'config', 'runtime.json');
const runtime = JSON.parse(fs.readFileSync(runtimePath, 'utf8'));

const sharedSecret = process.env.STUDYPLANNER_REALTIME_SECRET || runtime.realtime.shared_secret || '';
const internalBaseUrl = runtime.realtime.internal_base_url || 'http://127.0.0.1/studyplanner/studyplanner';
const groqApiKey = process.env.GROQ_API_KEY || runtime.groq.api_key || '';
const groqApiUrl = runtime.groq.api_url || 'https://api.groq.com/openai/v1/chat/completions';
const groqModel = runtime.groq.model || 'llama-3.1-8b-instant';
const port = Number(process.env.STUDYPLANNER_WS_PORT || 8081);

const clients = new Set();

function base64UrlEncode(input) {
  return Buffer.from(input).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function base64UrlDecode(input) {
  const normalized = input.replace(/-/g, '+').replace(/_/g, '/');
  const padding = 4 - (normalized.length % 4 || 4);
  return Buffer.from(normalized + '='.repeat(padding % 4), 'base64').toString('utf8');
}

function verifyToken(token) {
  const parts = token.split('.');
  if (parts.length !== 2 || !sharedSecret) {
    return null;
  }

  const [payloadPart, signaturePart] = parts;
  const expected = base64UrlEncode(crypto.createHmac('sha256', sharedSecret).update(payloadPart).digest());
  if (expected !== signaturePart) {
    return null;
  }

  const payload = JSON.parse(base64UrlDecode(payloadPart));
  if (!payload.exp || payload.exp < Math.floor(Date.now() / 1000)) {
    return null;
  }

  return payload;
}

function encodeFrame(message) {
  const payload = Buffer.from(message);
  const length = payload.length;
  let header;

  if (length < 126) {
    header = Buffer.from([0x81, length]);
  } else if (length < 65536) {
    header = Buffer.alloc(4);
    header[0] = 0x81;
    header[1] = 126;
    header.writeUInt16BE(length, 2);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x81;
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(length), 2);
  }

  return Buffer.concat([header, payload]);
}

function decodeFrames(buffer) {
  const messages = [];
  let offset = 0;

  while (offset + 2 <= buffer.length) {
    const first = buffer[offset];
    const second = buffer[offset + 1];
    const opcode = first & 0x0f;
    const masked = (second & 0x80) !== 0;
    let payloadLength = second & 0x7f;
    let headerLength = 2;

    if (payloadLength === 126) {
      if (offset + 4 > buffer.length) break;
      payloadLength = buffer.readUInt16BE(offset + 2);
      headerLength = 4;
    } else if (payloadLength === 127) {
      if (offset + 10 > buffer.length) break;
      payloadLength = Number(buffer.readBigUInt64BE(offset + 2));
      headerLength = 10;
    }

    const maskLength = masked ? 4 : 0;
    if (offset + headerLength + maskLength + payloadLength > buffer.length) break;

    const maskOffset = offset + headerLength;
    const payloadOffset = maskOffset + maskLength;
    let payload = buffer.slice(payloadOffset, payloadOffset + payloadLength);

    if (masked) {
      const mask = buffer.slice(maskOffset, maskOffset + 4);
      const unmasked = Buffer.alloc(payload.length);
      for (let i = 0; i < payload.length; i += 1) {
        unmasked[i] = payload[i] ^ mask[i % 4];
      }
      payload = unmasked;
    }

    if (opcode === 0x8) {
      messages.push({ type: 'close' });
    } else if (opcode === 0x9) {
      messages.push({ type: 'ping', payload: payload.toString('utf8') });
    } else if (opcode === 0x1) {
      messages.push({ type: 'text', payload: payload.toString('utf8') });
    }

    offset = payloadOffset + payloadLength;
  }

  return { messages, remaining: buffer.slice(offset) };
}

function sendJson(socket, payload) {
  if (socket.destroyed) return;
  socket.write(encodeFrame(JSON.stringify(payload)));
}

async function persistMessage(payload) {
  const rawBody = JSON.stringify(payload);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = crypto.createHmac('sha256', sharedSecret).update(`${timestamp}.${rawBody}`).digest('hex');

  await fetch(`${internalBaseUrl}/api/realtime_store.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Realtime-Timestamp': timestamp,
      'X-Realtime-Signature': signature
    },
    body: rawBody
  });
}

function broadcastToRoom(roomKey, payload) {
  for (const client of clients) {
    if (client.meta && client.meta.roomKey === roomKey) {
      sendJson(client.socket, payload);
    }
  }
}

async function handleAiMessage(client, data) {
  const userMessage = String(data.message || '').trim();
  if (!userMessage || !groqApiKey) {
    sendJson(client.socket, { type: 'error', scope: 'ai', message: 'Groq is not configured.' });
    return;
  }

  const recentHistory = Array.isArray(data.history) ? data.history.slice(-12) : [];

  await persistMessage({
    type: 'ai_user',
    user_id: client.meta.userId,
    subject_id: client.meta.subjectId,
    message_text: userMessage
  });

  const messages = [
    {
      role: 'system',
      content: `You are a secure AI study tutor for the subject ${client.meta.subjectName}. Keep answers concise, helpful, and student-friendly.`
    },
    ...recentHistory.map((item) => ({
      role: item.role === 'assistant' ? 'assistant' : 'user',
      content: String(item.text || '')
    })),
    {
      role: 'user',
      content: userMessage
    }
  ];

  try {
    const response = await fetch(groqApiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${groqApiKey}`
      },
      body: JSON.stringify({
        model: groqModel,
        temperature: 0.4,
        max_tokens: 1024,
        messages
      })
    });

    const payload = await response.json();
    const answer = payload?.choices?.[0]?.message?.content;
    if (!response.ok || !answer) {
      sendJson(client.socket, { type: 'error', scope: 'ai', message: payload?.error?.message || 'Unable to get AI response.' });
      return;
    }

    await persistMessage({
      type: 'ai_assistant',
      user_id: client.meta.userId,
      subject_id: client.meta.subjectId,
      message_text: answer
    });

    sendJson(client.socket, {
      type: 'ai_message',
      role: 'assistant',
      text: answer,
      created_at: new Date().toISOString()
    });
  } catch (error) {
    sendJson(client.socket, { type: 'error', scope: 'ai', message: 'AI request failed.' });
  }
}

async function handleRoomMessage(client, data) {
  const messageText = String(data.message || '').trim();
  if (!messageText) {
    return;
  }

  await persistMessage({
    type: 'room_message',
    user_id: client.meta.userId,
    subject_id: client.meta.subjectId,
    room_key: client.meta.roomKey,
    subject_name: client.meta.subjectName,
    user_name: client.meta.userName,
    message_text: messageText
  });

  broadcastToRoom(client.meta.roomKey, {
    type: 'room_message',
    user_id: client.meta.userId,
    user_name: client.meta.userName,
    text: messageText,
    created_at: new Date().toISOString()
  });
}

const server = http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end('Study Planner realtime server is running.');
});

server.on('upgrade', (req, socket) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    const token = url.searchParams.get('token') || '';
    const payload = verifyToken(token);

    if (!payload) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    const wsKey = req.headers['sec-websocket-key'];
    if (!wsKey) {
      socket.write('HTTP/1.1 400 Bad Request\r\n\r\n');
      socket.destroy();
      return;
    }

    const acceptKey = crypto
      .createHash('sha1')
      .update(wsKey + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
      .digest('base64');

    socket.write(
      'HTTP/1.1 101 Switching Protocols\r\n' +
      'Upgrade: websocket\r\n' +
      'Connection: Upgrade\r\n' +
      `Sec-WebSocket-Accept: ${acceptKey}\r\n\r\n`
    );

    const client = {
      socket,
      buffer: Buffer.alloc(0),
      meta: {
        userId: Number(payload.userId),
        userName: String(payload.userName || 'Student'),
        subjectId: Number(payload.subjectId),
        subjectName: String(payload.subjectName || 'Subject'),
        roomKey: String(payload.roomKey || '')
      }
    };

    clients.add(client);
    sendJson(socket, { type: 'system', message: 'connected' });

    socket.on('data', async (chunk) => {
      client.buffer = Buffer.concat([client.buffer, chunk]);
      const decoded = decodeFrames(client.buffer);
      client.buffer = decoded.remaining;

      for (const message of decoded.messages) {
        if (message.type === 'close') {
          socket.end();
          clients.delete(client);
          return;
        }

        if (message.type === 'ping') {
          socket.write(Buffer.from([0x8a, 0x00]));
          continue;
        }

        if (message.type === 'text') {
          try {
            const data = JSON.parse(message.payload);
            if (data.type === 'student_message') {
              await handleRoomMessage(client, data);
            } else if (data.type === 'ai_message') {
              await handleAiMessage(client, data);
            }
          } catch (error) {
            sendJson(socket, { type: 'error', message: 'Invalid realtime payload.' });
          }
        }
      }
    });

    socket.on('close', () => {
      clients.delete(client);
    });

    socket.on('end', () => {
      clients.delete(client);
    });

    socket.on('error', () => {
      clients.delete(client);
    });
  } catch (error) {
    socket.destroy();
  }
});

server.listen(port, () => {
  console.log(`Study Planner realtime server running on ws://127.0.0.1:${port}`);
});
