<?php

function syllabusUploadDirAbsolute() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'syllabus';
}

function syllabusUploadDirRelative() {
    return 'uploads/syllabus';
}

function ensureSyllabusUploadDir() {
    $dir = syllabusUploadDirAbsolute();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function normalizeExtractedSyllabusText($text) {
    $text = (string) $text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function extractTextFromTxtFile($filePath) {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return '';
    }
    return normalizeExtractedSyllabusText($content);
}

function extractTextFromDocxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        return '';
    }

    $xml = str_replace(['</w:p>', '</w:tr>', '</w:tc>'], ["\n", "\n", " "], $xml);
    $text = strip_tags($xml);
    return normalizeExtractedSyllabusText(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function extractTextFromDocFile($filePath) {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    $content = preg_replace('/[^(\x20-\x7F)\n\r\t]/', ' ', $content);
    return normalizeExtractedSyllabusText($content);
}

function extractTextFromPdfFile($filePath) {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    $extractPdfTextTokens = function ($pdfText) {
        $text = '';

        if (preg_match_all('/\((.*?)\)\s*Tj/s', $pdfText, $matches)) {
            foreach ($matches[1] as $match) {
                $text .= preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $match) . "\n";
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $pdfText, $arrayMatches)) {
            foreach ($arrayMatches[1] as $chunk) {
                if (preg_match_all('/\((.*?)\)/s', $chunk, $innerMatches)) {
                    foreach ($innerMatches[1] as $innerText) {
                        $text .= preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $innerText) . "\n";
                    }
                }
            }
        }

        return $text;
    };

    $text = $extractPdfTextTokens($content);

    if (trim($text) === '' && preg_match_all('/stream\s*(.*?)\s*endstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $stream = ltrim($stream, "\r\n");
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzdecode($stream);
            }
            if ($decoded === false && function_exists('zlib_decode')) {
                $decoded = @zlib_decode($stream);
            }
            if ($decoded === false) {
                continue;
            }

            $text .= "\n" . $extractPdfTextTokens($decoded);
        }
    }

    if (trim($text) === '') {
        $content = preg_replace('/[^(\x20-\x7E)\n\r\t]/', ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        if (preg_match_all('/[A-Za-z][A-Za-z0-9,\-\.\(\)\/ ]{20,}/', $content, $rawMatches)) {
            $text = implode("\n", $rawMatches[0]);
        }
    }

    return normalizeExtractedSyllabusText(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
}

function extractSyllabusTextByExtension($filePath, $extension) {
    if ($extension === 'txt') {
        return extractTextFromTxtFile($filePath);
    }
    if ($extension === 'docx') {
        return extractTextFromDocxFile($filePath);
    }
    if ($extension === 'doc') {
        return extractTextFromDocFile($filePath);
    }
    if ($extension === 'pdf') {
        return extractTextFromPdfFile($filePath);
    }
    return '';
}

function processUploadedSyllabusFile($file, $uid) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [
            'uploaded' => false,
            'text' => '',
            'file_name' => '',
            'file_path' => '',
            'source_type' => ''
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Unable to upload the syllabus file right now.'];
    }

    $originalName = $file['name'] ?? 'syllabus';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['txt', 'pdf', 'doc', 'docx'];

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['error' => 'Please upload a PDF, Word (.doc or .docx), or text (.txt) syllabus file.'];
    }

    $maxSizeBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSizeBytes) {
        return ['error' => 'The syllabus file must be 5 MB or smaller.'];
    }

    ensureSyllabusUploadDir();
    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '-', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim($safeBase, '-');
    if ($safeBase === '') {
        $safeBase = 'syllabus';
    }

    $storedName = 'u' . (int) $uid . '-' . time() . '-' . $safeBase . '.' . $extension;
    $absolutePath = syllabusUploadDirAbsolute() . DIRECTORY_SEPARATOR . $storedName;
    $relativePath = syllabusUploadDirRelative() . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        return ['error' => 'Unable to save the uploaded syllabus file.'];
    }

    $extractedText = extractSyllabusTextByExtension($absolutePath, $extension);
    if ($extractedText === '') {
        return ['error' => 'The uploaded file was saved, but text could not be extracted. Try a text file or a clearer DOCX/PDF file.'];
    }

    return [
        'uploaded' => true,
        'text' => $extractedText,
        'file_name' => $originalName,
        'file_path' => $relativePath,
        'source_type' => 'file'
    ];
}
