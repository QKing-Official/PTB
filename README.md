# PTB (Paymenter ToolBox)

Paymenter ToolBox (PTB) is a command-line tool designed to simplify managing themes in Paymenter. It allows you to easily export, import, rename, and list Paymenter themes packaged as `.paytheme` files.

## Features

- Export a Paymenter theme directory to a compressed `.paytheme` archive.
- Import a `.paytheme` archive into the Paymenter themes directory.
- Rename themes inside `.paytheme` archives, updating references automatically.
- List contents of `.paytheme` archives for quick inspection.

## Installation

You can install PTB by running the installer script directly:

```bash
curl -fsSL https://raw.githubusercontent.com/QKing-Official/PTB/refs/heads/main/installer | bash
This will download and install the ptb CLI tool for use on your system.
```
## Usage
ptb export -t <theme_path>                # Export theme directory to .paytheme file

ptb import -t <theme_file>                # Import .paytheme file into themes directory

ptb rename -t <theme_file> -n <new_name> # Rename theme inside .paytheme archive

ptb list -t <theme_file>                  # List contents of .paytheme archive

ptb --help                               # Show help

ptb --version                            # Show version

Default theme directory is /var/www/paymenter/themes, but you can specify a different target directory when importing. It's on the start of the ptb file (if you used the installer its at /usr/local/bin/ptb)

## Dependencies
tar
gzip
sed
npm

Make sure these dependencies are installed on your system. Ptb will tell you when they aren't installed.

## Credits
This tool is designed to support and enhance the Paymenter theme ecosystem.

Special thanks to the original Paymenter developers for their great work!

For more about Paymenter, visit the official pages:
https://github.com/paymenter/paymenter
https://paymenter.org/


# License
[QKOL v3.0](https://github.com/QKing-Official/QKOL/blob/main/v3.0/QKING_OPEN_LICENSE_v3.0) — Feel free to use and modify PTB as needed.

Created by QKing with internal depression
