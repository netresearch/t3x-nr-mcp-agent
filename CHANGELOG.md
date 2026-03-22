# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Integrated AI chat module in the TYPO3 backend (Admin Tools > AI Chat)
- Floating chat panel in the backend toolbar, persistent across module navigation
- Conversation history with resume, pin, and auto-archive support
- Background processing via CLI commands (`ai-chat:process`, `ai-chat:worker`)
- MCP (Model Context Protocol) integration for TYPO3 content management tools
- File/image upload support with per-provider capability detection (PNG, JPEG, WebP)
- PDF attachment support for providers implementing `DocumentCapableInterface` (Claude, Gemini); file picker accept filter is set dynamically per provider
- Group-based access control and concurrency caps
- Sanitized error messages (API keys and URLs are redacted)
- Transient error retry logic (429, 503, overloaded) with configurable backoff
- Architecture layer enforcement via phpat tests
