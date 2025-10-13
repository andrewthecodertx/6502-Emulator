#!/usr/bin/env bash
set -euo pipefail

die() {
  echo "Error: $*" >&2
  exit 1
}

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 <source.asm> [config.cfg]"
  echo ""
  echo "Assembles 6502 source code to binary."
  echo "Looks for .asm files in programs/ directory."
  echo "Outputs .bin files to roms/ directory."
  echo ""
  echo "If config file is not specified, looks for <source>.cfg in programs/"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROGRAMS_DIR="$ROOT_DIR/programs"
ROMS_DIR="$ROOT_DIR/roms"
BUILD_DIR="$ROOT_DIR/build"

# Parse input - handle both full paths and basenames
SRC_INPUT="$1"
if [[ -f "$SRC_INPUT" ]]; then
  SRC="$SRC_INPUT"
else
  SRC="$PROGRAMS_DIR/$SRC_INPUT"
fi

[[ -f "$SRC" ]] || die "Source file not found: $SRC"

BASENAME="$(basename "${SRC%.*}")"

# Determine config file
if [[ $# -eq 2 ]]; then
  CFG_INPUT="$2"
  if [[ -f "$CFG_INPUT" ]]; then
    CFG="$CFG_INPUT"
  else
    CFG="$PROGRAMS_DIR/$CFG_INPUT"
  fi
else
  CFG="$PROGRAMS_DIR/$BASENAME.cfg"
fi

[[ -f "$CFG" ]] || die "Config file not found: $CFG"

# Setup directories
WORK_DIR="$BUILD_DIR/$BASENAME"
mkdir -p "$ROMS_DIR" "$WORK_DIR"

# Output files
OBJ="$WORK_DIR/$BASENAME.o"
BIN="$ROMS_DIR/$BASENAME.bin"
MAP="$WORK_DIR/$BASENAME.map"
LBL="$WORK_DIR/$BASENAME.lbl"
LST="$WORK_DIR/$BASENAME.lst"

# Find assembler and linker
COMPILER="$ROOT_DIR/bin/ca65"
LINKER="$ROOT_DIR/bin/ld65"

command -v "$COMPILER" >/dev/null || die "ca65 not found at $COMPILER"
command -v "$LINKER" >/dev/null || die "ld65 not found at $LINKER"

# Assemble
echo "Assembling $BASENAME.asm ..."
"$COMPILER" -g -l "$LST" -o "$OBJ" "$SRC" || die "Assembly failed"

# Link
echo "Linking with $BASENAME.cfg ..."
"$LINKER" -C "$CFG" -m "$MAP" -Ln "$LBL" -o "$BIN" "$OBJ" || die "Linking failed"

# Success
SIZE=$(wc -c < "$BIN")
echo ""
echo "Success! Generated $BASENAME.bin ($SIZE bytes)"
echo "Output: $BIN"
echo "Map:    $MAP"
echo "Labels: $LBL"
