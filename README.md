# Learning with Texts

<p align="center">
  <img src="https://github.com/HugoFara/lwt/raw/main/assets/images/lwt_icon_512.png" alt="LWT logo - an open book" width="200"/>
</p>

<p align="center">
  <strong>Learn languages by reading — with smart suggestions and built-in definitions</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/hugofara/lwt"><img src="https://poser.pugx.org/hugofara/lwt/v" alt="Latest Stable Version"></a>
  <a href="https://packagist.org/packages/hugofara/lwt"><img src="https://poser.pugx.org/hugofara/lwt/require/php" alt="PHP Version"></a>
  <a href="https://github.com/HugoFara/lwt/actions/workflows/php.yml"><img src="https://github.com/HugoFara/lwt/actions/workflows/php.yml/badge.svg" alt="PHP CI"></a>
  <a href="https://github.com/HugoFara/lwt/actions/workflows/frontend.yml"><img src="https://github.com/HugoFara/lwt/actions/workflows/frontend.yml/badge.svg" alt="Frontend CI"></a>
  <a href="https://github.com/HugoFara/lwt/actions/workflows/security_check.yml"><img src="https://github.com/HugoFara/lwt/actions/workflows/security_check.yml/badge.svg" alt="Psalm"></a>
  <a href="https://github.com/HugoFara/lwt/actions/workflows/phpcs.yml"><img src="https://github.com/HugoFara/lwt/actions/workflows/phpcs.yml/badge.svg" alt="PHPCS"></a>
  <a href="https://github.com/HugoFara/lwt/actions/workflows/docker-image.yml"><img src="https://github.com/HugoFara/lwt/actions/workflows/docker-image.yml/badge.svg" alt="Docker"></a>
  <a href="http://unlicense.org/"><img src="https://poser.pugx.org/hugofara/lwt/license" alt="License"></a>
</p>

---

**Learning with Texts** (LWT) is a self-hosted web app for language learning through reading. **Import any text you want to study** — a novel, a news article, song lyrics — or let LWT **suggest reading material adapted to your level** from open libraries. Either way, words are enriched with **translations and definitions from open sources**, and you build vocabulary through reading and spaced repetition.

Don't know where to start? LWT suggests books from [Project Gutenberg](https://www.gutenberg.org/) ranked by difficulty, plus curated news feeds in 19 languages. Already have a text you love? Paste it in and start reading. As you mark words, suggestions get smarter and new vocabulary is pre-enriched from [Wiktionary](https://www.wiktionary.org/).

> [!IMPORTANT]
> This is a **community-maintained fork** and a significant upgrade over the original LWT, created by [hapepo23](https://sourceforge.net/u/hapepo23/) and first released in 2011. It brings an easy-to-use interface, text suggestions and reading statistics, modern PHP support (8.2–8.5), mobile support, and compatibility with the modern web. The original LWT is still available on [SourceForge](https://sourceforge.net/projects/learning-with-texts); a snapshot of its code is also preserved on the [`official` branch](https://github.com/HugoFara/lwt/tree/official) of this repository.

> [!TIP]
> **There's a mobile app now: [Lukaisu](https://github.com/lukaisu/lukaisu).** A
> new **local-first** Android client (heading to F-Droid) for learning by
> reading. It works fully offline with an on-device database; connecting a server
> is optional and adds Chinese/Japanese word-splitting, text-to-speech,
> transcription, and online text discovery. LWT 3.1+ servers are compatible, so
> you can read your existing library on your phone.

## Table of Contents

- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
- [Features](#features)
- [Installation](#installation)
- [Requirements](#requirements)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [Alternatives](#alternatives)
- [License](#license)

## Quick Start

The fastest way to get started is with Docker:

```bash
git clone https://github.com/HugoFara/lwt.git
cd lwt
docker compose up
```

Then open <http://localhost:8010/> in your browser.

## How It Works

**1. Pick a text** — Import something you want to read, or browse suggestions matched to your level from Project Gutenberg and curated news feeds.

**2. Read and learn** — Unknown words are highlighted. Click any word to see its translation (pre-loaded from Wiktionary) and save it to your vocabulary.

![Reading text](https://hugofara.github.io/lwt/assets/images/reading-text.png)

**3. Review with context** — Practice vocabulary with spaced repetition, always seeing words in their original context.

![Reviewing word](https://hugofara.github.io/lwt/assets/images/reviewing-word.png)

**4. Keep going** — As you mark words known or unknown, difficulty estimates adapt. Suggestions get smarter, and your next text is always ready.

Unlike flashcard apps like [Anki](https://apps.ankiweb.net/), LWT keeps words connected to the texts where you found them. We include an Anki exporter if you want both.

## Features

### Smart Content Suggestions

- **Book suggestions** — Browse Project Gutenberg's catalog, ranked by difficulty for your level
- **Curated news feeds** — [Ready-to-use RSS sources](data/curated_feeds.json) for 19 languages (Arabic, Chinese, French, German, Japanese, Korean, Spanish, and more)
- **Difficulty estimation** — Books are classified easy/medium/hard based on subject matter and your known vocabulary
- **Adaptive recommendations** — Suggestions improve as you learn more words

### Built-in Enrichment

- **Wiktionary definitions** — Starter vocabulary comes pre-enriched with translations and definitions from open sources
- **Click-to-translate** — Instant dictionary lookups while reading
- **Bulk translation** — Translate multiple new words at once
- **Text-to-speech** — Hear pronunciation of words

### Reading & Review

- **40+ languages supported** — Roman, right-to-left, and East-Asian writing systems
- **Audio integration** — Sync audio tracks with your texts
- **Spaced repetition** — Review words at optimal intervals, always in context
- **Progress tracking** — Statistics to monitor your learning
- **Multi-word selection** — Click and drag to select phrases

### More Features

| Feature | Description |
| --- | --- |
| Mobile support | Responsive design for phones and tablets |
| Themes | Customizable appearance |
| Keyboard shortcuts | Navigate efficiently while reading |
| Video embedding | Include videos from YouTube and other platforms |
| MeCab integration | Japanese word-by-word translation |
| Position memory | Resume reading where you left off |
| Anki export | Export vocabulary to Anki for additional review |

### Technical Improvements

- **Smaller database** — Optimized schema reduces storage significantly
- **Long expressions** — Save phrases up to 250 characters (was limited to 9)
- **Better search** — Improved querying for words and texts
- **Modern PHP** — Supports PHP 8.2, 8.3, 8.4, and 8.5

## Installation

### Docker (Recommended)

Works on any OS with Docker installed.

#### Option A: Quick installer

```bash
# Use the lightweight installer
git clone https://github.com/HugoFara/lwt-docker-installer.git
cd lwt-docker-installer
docker compose up
```

#### Option B: Build from source

```bash
git clone https://github.com/HugoFara/lwt.git
cd lwt

# Optional: customize settings (database password, etc.)
cp .env.example .env
# Edit .env with your preferences

docker compose up
```

Access at <http://localhost:8010/>

Configuration is done via `.env` file (see `.env.example` for all options).

### Linux

```bash
# Download and extract the latest release
wget https://github.com/HugoFara/lwt/archive/refs/heads/main.zip
unzip main.zip && cd lwt-main

# Run the installer
chmod +x ./INSTALL.sh
./INSTALL.sh
```

### Manual Installation (Windows/macOS/Linux)

1. Install prerequisites: PHP 8.2+, MySQL/MariaDB, a web server (Apache/Nginx)
2. Clone or download the repository
3. Configure the database:

   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

4. Install dependencies:

   ```bash
   composer install
   npm install && npm run build:all
   ```

See the [Installation Guide](https://hugofara.github.io/lwt/guide/installation) for detailed instructions.

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.2, 8.3, 8.4, or 8.5 |
| MySQL/MariaDB | 5.7+ / 10.3+ |
| PHP Extensions | mysqli, mbstring, dom, zip (for EPUB support) |

For development, you'll also need [Composer](https://getcomposer.org/) and [Node.js](https://nodejs.org/) 18+.

## Documentation

- **[User Guide](https://hugofara.github.io/lwt/guide/getting-started)** — Getting started and usage
- **[API Reference](https://hugofara.github.io/lwt/reference/features)** — Features and REST API documentation
- **[Developer Docs](https://hugofara.github.io/lwt/developer/api)** — Architecture and contribution guide

## Contributing

Contributions are welcome! Here's how to set up a development environment:

```bash
git clone https://github.com/HugoFara/lwt.git
cd lwt
composer install --dev
npm install
```

### Development Commands

```bash
# Run tests
composer test              # PHP tests with coverage
npm test                   # Frontend tests

# Code quality
./vendor/bin/psalm         # Static analysis
npm run lint               # ESLint
npm run typecheck          # TypeScript checking

# Build assets
npm run dev                # Development server with HMR
npm run build:all          # Production build
```

### Translations

LWT is available in nine languages. Translations live in `locale/<code>/` and fall
back to English key by key, so a partial contribution is welcome — see
[Translations](CONTRIBUTING.md#translations) for current completion and how to help.

### Branch Strategy

| Branch | Purpose |
| --- | --- |
| `main` | Stable releases |
| `develop` | Development and testing |
| `official` | Tracks official LWT releases |
| `badges` | Generated locale-completion badges (do not edit by hand) |

## Alternatives

If LWT doesn't fit your needs, consider these projects:

- **[LUTE v3](https://github.com/jzohrab/lute-v3)** — Modern rewrite using Python/Flask, actively developed
- **[LinguaCafe](https://github.com/simjanos-dev/LinguaCafe)** — Beautiful Vue.js/PHP implementation
- **[FLTR](https://sourceforge.net/projects/foreign-language-text-reader/)** — Java desktop app by LWT's original author

## License

This project is released into the **public domain** under the [Unlicense](UNLICENSE.md). You're free to use, modify, and distribute it however you like.

---

<p align="center">
  <strong>Happy reading, happy learning!</strong>
</p>
