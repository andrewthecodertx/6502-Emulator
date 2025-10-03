#!/bin/bash
# Simple build script for PHP-6502 assembly programs
# Usage: ./simple_build.sh program_name config_name

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PROGRAMS_DIR="$PROJECT_ROOT/programs"
ROMS_DIR="$PROJECT_ROOT/roms"

if [ $# -ne 2 ]; then
  echo "Usage: $0 <program.asm> <config.cfg>"
  echo "Example: $0 bios.asm bios.cfg"
  echo "Example: $0 wozmon.asm wozmon.cfg"
  echo ""
  echo "Available configs:"
  ls "$PROGRAMS_DIR"/*.cfg 2>/dev/null | xargs -n1 basename
  exit 1
fi

ASM_FILE="$1"
CFG_FILE="$2"
BASENAME=$(basename "$ASM_FILE" .asm)

SOURCE_PATH="$PROGRAMS_DIR/$ASM_FILE"
CONFIG_PATH="$PROGRAMS_DIR/$CFG_FILE"
OUTPUT_PATH="$ROMS_DIR/$BASENAME.bin"
JSON_PATH="$ROMS_DIR/$BASENAME.json"
MAP_PATH="/tmp/$BASENAME.map"
OBJ_PATH="/tmp/$BASENAME.o"

ASSEMBLER="../bin/ca65"
LINKER="../bin/cl65"

echo "Building $BASENAME..."
echo "Source: $SOURCE_PATH"
echo "Config: $CONFIG_PATH"
echo "Output: $OUTPUT_PATH"

# Check files exist
if [ ! -f "$SOURCE_PATH" ]; then
  echo "Error: $SOURCE_PATH not found!"
  exit 1
fi

if [ ! -f "$CONFIG_PATH" ]; then
  echo "Error: $CONFIG_PATH not found!"
  exit 1
fi

# Build
cd "$PROGRAMS_DIR"
echo "Assembling..."
"$ASSEMBLER" -t none "$ASM_FILE" -o "$OBJ_PATH"
if [ $? -ne 0 ]; then
  echo "Assembly failed!"
  exit 1
fi

echo "Linking..."
"$LINKER" -C "$CFG_FILE" -o "$OUTPUT_PATH" "$OBJ_PATH" -m "$MAP_PATH"
if [ $? -ne 0 ]; then
  echo "Linking failed!"
  rm -f "$OBJ_PATH" "$MAP_PATH"
  exit 1
fi

# Generate metadata JSON
echo "Generating metadata..."
BINARY_SIZE=$(stat -c%s "$OUTPUT_PATH" 2>/dev/null || stat -f%z "$OUTPUT_PATH" 2>/dev/null)
TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# Extract .org directives from assembly source
ORG_DIRECTIVES=$(grep -i "^\s*\.org" "$SOURCE_PATH" | sed 's/.*\.org\s*\$\?\([0-9A-Fa-f]*\).*/0x\1/' | tr '\n' ',' | sed 's/,$//')

# Parse linker map for segments
SEGMENTS=""
if [ -f "$MAP_PATH" ]; then
  # Extract segment information from map file
  SEGMENTS=$(awk '/^Segment list:/,/^$/ {
        if ($1 != "Segment" && $1 != "Name" && $1 != "----" && NF >= 4) {
            printf "{\"name\":\"%s\",\"start\":\"0x%s\",\"end\":\"0x%s\",\"size\":\"0x%s\"},", $1, $2, $3, $4
        }
    }' "$MAP_PATH" | sed 's/,$//')
fi

# Check for BIOS conflicts (assuming BIOS is at 0x8000-0x81B2 + some safety margin)
BIOS_START="0x8000"
BIOS_END="0x8500" # Conservative estimate with margin
CONFLICTS_WITH_BIOS="false"

# Simple conflict check - look for any segment starting in BIOS range
if [ -f "$MAP_PATH" ]; then
  BIOS_CONFLICT=$(awk '/^Segment list:/,/^$/ {
        if ($1 != "Segment" && $1 != "Name" && $1 != "----" && NF >= 4) {
            start = strtonum("0x" $2)
            if (start >= 0x8000 && start <= 0x8500) print "true"
        }
    }' "$MAP_PATH")
  if [ "$BIOS_CONFLICT" = "true" ]; then
    CONFLICTS_WITH_BIOS="true"
  fi
fi

# Create JSON metadata
cat >"$JSON_PATH" <<EOF
{
  "name": "$BASENAME",
  "binary_file": "$BASENAME.bin",
  "load_address": "$(echo $ORG_DIRECTIVES | cut -d',' -f1)",
  "size": $BINARY_SIZE,
  "org_directives": [$(echo $ORG_DIRECTIVES | sed 's/0x/\"0x/g' | sed 's/,/\",\"/g' | sed 's/$/\"/' | sed 's/^\"$//')],
  "linker_segments": [${SEGMENTS}],
  "conflicts_with_bios": $CONFLICTS_WITH_BIOS,
  "bios_protected_range": "$BIOS_START-$BIOS_END",
  "build_timestamp": "$TIMESTAMP",
  "assembler": "ca65",
  "linker": "ld65",
  "config_file": "$CFG_FILE",
  "source_file": "$ASM_FILE"
}
EOF

rm -f "$OBJ_PATH" "$MAP_PATH"
echo "Build complete: $OUTPUT_PATH"
echo "Metadata: $JSON_PATH"
echo "Size: $BINARY_SIZE bytes"

