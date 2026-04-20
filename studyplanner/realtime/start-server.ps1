$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

Write-Host "Starting Study Planner realtime server on ws://127.0.0.1:8081" -ForegroundColor Cyan
Write-Host "Keep this window open while using realtime chat." -ForegroundColor Yellow

node "$PSScriptRoot\chat-server.js"
