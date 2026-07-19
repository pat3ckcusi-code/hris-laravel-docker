<?php

// One-off generator for PWA icon assets. Re-run any time the source logo changes:
//   docker exec hris-dev-app php /var/www/html/scripts/generate-pwa-icons.php

const SRC = __DIR__ . '/../public/assets/login/Calapan_City_Logo.png'; // swap to chrmd1.png for a sharper source
const OUT_DIR = __DIR__ . '/../public/icons';
const THEME_BG = [0x0f, 0x17, 0x2a]; // #0f172a — maskable background
const LIGHT_BG = [0xf8, 0xfa, 0xfc]; // #f8fafc — apple-touch-icon background

function loadSource(): GdImage
{
    $image = imagecreatefrompng(SRC);
    if (! $image) {
        fwrite(STDERR, 'Failed to load source image: ' . SRC . PHP_EOL);
        exit(1);
    }

    return $image;
}

function resizeTransparent(GdImage $src, int $size): GdImage
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);

    return $canvas;
}

function flatten(GdImage $src, int $size, array $bgRgb, float $scale): GdImage
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $canvas = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($canvas, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    imagefill($canvas, 0, 0, $bg);

    $logoSize = (int) round($size * $scale);
    $offset = (int) round(($size - $logoSize) / 2);

    imagecopyresampled($canvas, $src, $offset, $offset, 0, 0, $logoSize, $logoSize, $srcW, $srcH);

    return $canvas;
}

function writePng(GdImage $image, string $path): void
{
    imagepng($image, $path);
    imagedestroy($image);
    echo "wrote {$path}\n";
}

if (! is_dir(OUT_DIR)) {
    mkdir(OUT_DIR, 0755, true);
}

$src = loadSource();

writePng(resizeTransparent($src, 192), OUT_DIR . '/icon-192.png');
writePng(resizeTransparent($src, 512), OUT_DIR . '/icon-512.png');
writePng(resizeTransparent($src, 32), OUT_DIR . '/icon-32.png');
writePng(flatten($src, 180, LIGHT_BG, 0.92), OUT_DIR . '/apple-touch-icon.png');
writePng(flatten($src, 512, THEME_BG, 0.80), OUT_DIR . '/icon-512-maskable.png');

// Overwrite the (currently empty) favicon.ico with real PNG bytes — modern
// browsers content-sniff the actual format at .ico URLs.
copy(OUT_DIR . '/icon-32.png', __DIR__ . '/../public/favicon.ico');
echo "wrote " . __DIR__ . "/../public/favicon.ico (PNG bytes)\n";

imagedestroy($src);
