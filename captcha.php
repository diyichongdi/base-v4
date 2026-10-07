<?php
require_once 'security.php';

if (!checkRateLimit('captcha', 10, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    exit;
}

$code = generateCaptcha();

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$width = 200;
$height = 60;

$svg = '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">
<rect width="100%" height="100%" fill="#151515"/>';

for ($i = 0; $i < 80; $i++) {
    $x = rand(0, $width);
    $y = rand(0, $height);
    $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="1" fill="#282828"/>';
}

for ($i = 0; $i < 3; $i++) {
    $x1 = rand(0, $width);
    $y1 = rand(0, $height);
    $x2 = rand(0, $width);
    $y2 = rand(0, $height);
    $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#333" stroke-width="1"/>';
}

$fonts = ['Arial', 'Verdana', 'Times New Roman', 'Courier New'];
$fontSize = rand(24, 28);
$x = 20;
for ($i = 0; $i < strlen($code); $i++) {
    $y = rand(35, 50);
    $rotation = rand(-15, 15);
    $font = $fonts[array_rand($fonts)];
    $svg .= '<text x="' . $x . '" y="' . $y . '" font-family="' . $font . '" font-size="' . $fontSize . '" fill="#3df65a" transform="rotate(' . $rotation . ' ' . $x . ' ' . $y . ')">' . htmlspecialchars($code[$i]) . '</text>';
    $x += 28;
}

$svg .= '</svg>';

echo $svg;
exit;
?>
