# 🕐 Clockify CLI Wizard

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-clockify--cli--wizard-orange.svg)](https://packagist.org/packages/mi-lopez/clockify-cli-wizard)

> A beautiful, intelligent CLI wizard for seamless time tracking with Clockify and Jira integration.

Transform your time tracking workflow with smart automation, Git integration, and an intuitive command-line interface designed for developers.

## ✨ Features

### 🚀 **Smart Time Tracking**
- **Auto-detection** from Git branch names (extracts ticket IDs like `CAM-451`, `PROJ-123`)
- **Interactive wizard** with intelligent suggestions
- **Multiple time formats** support (`2h`, `1h30m`, `90m`, `9:30am-11:30am`)
- **Real-time timer** with start/stop functionality

### 🎯 **Jira Integration**
- **Automatic ticket fetching** with summary and project info
- **Project mapping** between Jira and Clockify
- **Recent issues** selection for quick logging
- **Task auto-creation** with formatted names

### 📊 **Advanced Reporting**
- **Daily/Weekly summaries** with detailed breakdowns
- **Timeline views** with gap detection
- **Export capabilities** (CSV, JSON)
- **Progress tracking** against weekly targets
- **Project and task analytics**

### 🌍 **Timezone Intelligence**
- **Smart timezone handling** (defaults to Chile/Santiago)
- **Local time display** with UTC API communication
- **Working hours awareness**
- **International timezone support**

### 🎨 **Enhanced User Experience**
- **Beautiful CLI interface** with colors and emojis
- **Pagination and search** for large project lists
- **Progress indicators** and status messages
- **Configuration wizard** with validation
- **Error handling** with helpful suggestions

## 📦 Installation

### Via Composer (Recommended)

```bash
composer global require mi-lopez/clockify-cli-wizard
```

### Manual Installation

```bash
git clone https://github.com/mi-lopez/clockify-cli-wizard.git
cd clockify-cli-wizard
composer install
chmod +x bin/clockify-wizard
```

### Add to PATH (Global Access)

```bash
# Add to your ~/.bashrc or ~/.zshrc
export PATH="$PATH:$HOME/.composer/vendor/bin"

# Or create a symlink
ln -s /path/to/clockify-cli-wizard/bin/clockify-wizard /usr/local/bin/clockify-wizard
```

## ⚙️ Configuration

### Initial Setup

```bash
clockify-wizard configure
```

The wizard will guide you through:

1. **Clockify API Setup**
    - API key (from [clockify.me/user/settings](https://clockify.me/user/settings))
    - Workspace selection
    - User validation

2. **Jira Integration** (Optional)
    - Jira URL (`https://company.atlassian.net`)
    - Email address
    - API token (from [Atlassian API tokens](https://id.atlassian.com/manage-profile/security/api-tokens))

3. **General Settings**
    - Timezone configuration
    - Default duration settings
    - Auto-detection preferences

### Environment Variables (Optional)

```bash
export CLOCKIFY_API_KEY="your-api-key"
export CLOCKIFY_WORKSPACE_ID="workspace-id"
export JIRA_URL="https://company.atlassian.net"
export JIRA_EMAIL="your-email@company.com"
export JIRA_TOKEN="your-jira-token"
```

## 🚀 Quick Start

### Basic Time Logging

```bash
# Log 2 hours with auto-detection from Git branch
clockify-wizard log 2h --auto

# Interactive mode with wizard
clockify-wizard log --interactive

# Log specific time range
clockify-wizard log --start 9:30am --end 11:30am --task CAM-451

# Quick log with description
clockify-wizard log 1h30m --task PROJ-123 --description "Bug fixing"
```

### Timer Management

```bash
# Start a timer
clockify-wizard start CAM-451

# Stop active timer
clockify-wizard stop

# Check current status
clockify-wizard status
```

### Task Management

```bash
# Create task from Jira ticket
clockify-wizard create-task CAM-451

# List all tasks
clockify-wizard list-tasks

# Filter tasks by project
clockify-wizard list-tasks --project "My Project"
```

### Reports and Analytics

```bash
# Today's summary
clockify-wizard today --detailed

# Weekly report
clockify-wizard week --detailed --export week.csv

# Custom reports
clockify-wizard reports --period month --group-by project --export monthly.csv
```

## 📋 Available Commands

| Command | Description | Aliases |
|---------|-------------|---------|
| `configure` | Setup wizard for Clockify and Jira | `config`, `setup` |
| `start` | Start a timer for time tracking | `begin` |
| `stop` | Stop the active timer | `end` |
| `log` | Log time with smart detection | `l` |
| `today` | Show today's time summary | `td` |
| `week` | Show weekly time summary | `wk` |
| `create-task` | Create task from Jira ticket | `task` |
| `list-tasks` | List tasks from projects | `tasks`, `ls` |
| `reports` | Generate detailed reports | `report` |
| `status` | Show current status and config | `info` |

## 🎯 Advanced Usage

### Git Integration

The tool automatically detects ticket IDs from Git branch names:

```bash
# Branch: feature/CAM-451-user-authentication
git checkout feature/CAM-451-user-authentication
clockify-wizard log 2h --auto  # Automatically detects CAM-451
```

Supported patterns:
- `feature/CAM-451-description`
- `bugfix/PROJ-123`
- `CAM-451-hotfix`
- `TICKET-456`

### Project Mapping

Automatically map Jira projects to Clockify projects:

```bash
# First time mapping
clockify-wizard log 1h --task CAM-451
# Wizard will ask to map Jira project "CAM" to a Clockify project
# Future CAM tickets will use the same mapping automatically
```

### Time Format Examples

```bash
# Duration formats
clockify-wizard log 2h           # 2 hours
clockify-wizard log 1h30m        # 1 hour 30 minutes
clockify-wizard log 90m          # 90 minutes
clockify-wizard log 1.5h         # 1.5 hours

# Time range formats
clockify-wizard log --start 9am --end 11:30am
clockify-wizard log --start 14:30 --end 16:00
clockify-wizard log 2h --end-now  # 2 hours ending now
```

### Smart Suggestions

The interactive mode provides intelligent suggestions:

```bash
clockify-wizard log --interactive
```

- **Recent Jira tickets** for quick selection
- **Time suggestions** based on current time and common work hours
- **Project suggestions** based on Git context and history

## 📊 Reports and Analytics

### Daily Summary

```bash
clockify-wizard today --detailed
```

Shows:
- Total time tracked
- Project breakdown with percentages
- Timeline view with gaps
- Active timer status

### Weekly Analysis

```bash
clockify-wizard week --detailed --export week.csv
```

Features:
- Daily breakdown for the entire week
- Target progress (40-hour work week)
- Project distribution
- Export capabilities

### Custom Reports

```bash
# Monthly project report
clockify-wizard reports --period month --group-by project

# Task-level analysis
clockify-wizard reports --group-by task --start 2024-01-01 --end 2024-01-31

# Export options
clockify-wizard reports --format csv --export report.csv
clockify-wizard reports --format json --export report.json
```

## 🔧 Configuration Details

### Config File Location

```bash
~/.clockify-cli-config.json
```

### Sample Configuration

```json
{
    "version": "1.0",
    "timezone": "America/Santiago",
    "clockify": {
        "api_key": "your-api-key",
        "workspace_id": "workspace-id",
        "user_id": "user-id"
    },
    "jira": {
        "url": "https://company.atlassian.net",
        "email": "your-email@company.com",
        "token": "your-jira-token"
    },
    "project_mappings": {
        "CAM": "clockify-project-id",
        "PROJ": "another-clockify-project-id"
    },
    "timer": {
        "default_duration": "1h",
        "round_to_minutes": 15,
        "auto_detect_branch": true
    }
}
```

### Reset Configuration

```bash
clockify-wizard configure --reset
```

## 🌍 Timezone Support

The tool intelligently handles timezones:

- **Default**: `America/Santiago` (Chile)
- **Auto-detection** from system settings
- **Manual configuration** during setup
- **UTC conversion** for API communication
- **Local display** for user interface

### Supported Timezones

- `America/Santiago` - Chile Continental
- `Pacific/Easter` - Easter Island
- `UTC` - Coordinated Universal Time
- `America/New_York` - Eastern Time
- `America/Los_Angeles` - Pacific Time
- `Europe/Madrid` - Central European Time

## 🛠️ Development

### Requirements

- PHP ^8.1
- Composer
- Git (for auto-detection features)

### Local Development

```bash
git clone https://github.com/mi-lopez/clockify-cli-wizard.git
cd clockify-cli-wizard
composer install

# Run tests
composer test

# Code style check
composer cs-check

# Fix code style
composer cs-fix

# Static analysis
composer phpstan
```

### Project Structure

```
src/
├── Client/          # API clients (Clockify, Jira)
├── Commands/        # Console commands
├── Config/          # Configuration management
├── Console/         # Application setup
└── Helper/          # Utility classes

bin/
└── clockify-wizard  # Executable script

tests/               # PHPUnit tests
```

## 🤝 Contributing

We welcome contributions! Please read our [Contributing Guide](CONTRIBUTING.md) for details.

### Development Workflow

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Add tests for new functionality
5. Run the test suite (`composer test`)
6. Commit your changes (`git commit -m 'Add amazing feature'`)
7. Push to the branch (`git push origin feature/amazing-feature`)
8. Open a Pull Request

### Reporting Issues

Please use the [GitHub Issues](https://github.com/mi-lopez/clockify-cli-wizard/issues) page to report bugs or request features.

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and updates.

## 🔒 Security

If you discover any security-related issues, please email [miguel.lopezt86@gmail.com](mailto:miguel.lopezt86@gmail.com) instead of using the issue tracker.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- [Clockify](https://clockify.me) for the excellent time tracking API
- [Atlassian](https://atlassian.com) for the Jira API
- [Symfony Console](https://symfony.com/doc/current/components/console.html) for the CLI framework
- [Carbon](https://carbon.nesbot.com) for date/time manipulation
- All contributors and users of this project

## 💬 Support

- 📖 **Documentation**: This README and inline help (`clockify-wizard --help`)
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/mi-lopez/clockify-cli-wizard/issues)
- 💡 **Feature Requests**: [GitHub Issues](https://github.com/mi-lopez/clockify-cli-wizard/issues)
- 📧 **Email**: [miguel.lopezt86@gmail.com](mailto:miguel.lopezt86@gmail.com)

---

<p align="center">
Made with ❤️ for developers who value efficient time tracking
</p>

<p align="center">
<a href="#-features">Features</a> •
<a href="#-installation">Installation</a> •
<a href="#-quick-start">Quick Start</a> •
<a href="#-advanced-usage">Advanced Usage</a> •
<a href="#-development">Development</a>
</p>
