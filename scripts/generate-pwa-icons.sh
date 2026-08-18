#!/bin/bash
# Generate PWA icons from SVG source

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ICONS_DIR="$PROJECT_ROOT/public/icons"
SVG_SOURCE="$ICONS_DIR/pwa-icon.svg"

# Prefer ImageMagick 7 `magick`, fall back to ImageMagick 6 `convert`
if command -v magick &> /dev/null; then
    IM="magick"
elif command -v convert &> /dev/null && convert -version &> /dev/null 2>&1 && convert -version 2>/dev/null | grep -qi imagemagick; then
    IM="convert"
else
    echo "❌ ImageMagick is not installed. Please install it:"
    echo "   Ubuntu/Debian: sudo apt install imagemagick"
    echo "   macOS: brew install imagemagick"
    echo "   Windows: install ImageMagick 7 (provides 'magick')"
    exit 1
fi

echo "🎨 Generating PWA icons from $SVG_SOURCE"

# Icon sizes required for PWA (48 is referenced by manifest.json)
SIZES=(48 72 96 128 144 152 192 384 512)

for size in "${SIZES[@]}"; do
    output="$ICONS_DIR/pwa-${size}x${size}.png"
    echo "  📦 Generating ${size}x${size}..."
    $IM -background none "$SVG_SOURCE" -resize "${size}x${size}" "$output"
done

# Generate Apple Touch Icon (180x180)
echo "  🍎 Generating Apple Touch Icon (180x180)..."
$IM -background none "$SVG_SOURCE" -resize "180x180" "$ICONS_DIR/apple-touch-icon.png"

# Generate favicon (32x32, 16x16)
echo "  🔖 Generating favicons..."
$IM -background none "$SVG_SOURCE" -resize "32x32" "$ICONS_DIR/favicon-32x32.png"
$IM -background none "$SVG_SOURCE" -resize "16x16" "$ICONS_DIR/favicon-16x16.png"

# Generate ICO file (multi-resolution)
echo "  💾 Generating favicon.ico..."
$IM "$ICONS_DIR/favicon-16x16.png" "$ICONS_DIR/favicon-32x32.png" "$ICONS_DIR/favicon.ico"

echo "✅ All PWA icons generated successfully!"
echo ""
echo "Generated icons:"
for size in "${SIZES[@]}"; do
    echo "  ✓ pwa-${size}x${size}.png"
done
echo "  ✓ apple-touch-icon.png"
echo "  ✓ favicon-32x32.png"
echo "  ✓ favicon-16x16.png"
echo "  ✓ favicon.ico"
