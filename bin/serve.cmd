@echo off
rem MAURO local development server (Windows). Double-click or run: bin\serve.cmd
rem Uses the project-local PHP settings in deploy\local-php (see bin\dev-php.ps1).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev-php.ps1" %*
