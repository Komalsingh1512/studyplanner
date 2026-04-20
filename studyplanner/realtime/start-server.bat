@echo off
cd /d %~dp0\..
echo Starting Study Planner realtime server on ws://127.0.0.1:8081
echo Keep this window open while using realtime chat.
node "%~dp0chat-server.js"
