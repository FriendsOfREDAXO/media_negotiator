<?php

use media_negotiator\Helper;

?>


<h2>Anleitung:</h2>
<p>Dem Medientypen den Effekt "Negotiate image format" hinzufügen.
    Danach wird automatisch eines der folgenden Formate ausgeliefert: avif, webp, jpg (in dieser Reihenfolge).
</p>

<h2>Verfügbare Funktionen:</h2>

<?php


if (class_exists(Imagick::class)) {
    echo '<p class="text-success bold"><b>Imagick ist installiert</b></p>';

    $imagick = new Imagick();
    $imagickFormats = $imagick->queryFormats();

    if (in_array("WEBP", $imagickFormats)) {
        echo '<p class="text-success bold"><b>Imagick kann WEBP</b></p>';
    } else {
        echo '<p class="text-danger bold"><b>Imagick kann kein WEBP</b></p>';
    }
    if (in_array("AVIF", $imagickFormats)) {
        echo '<p class="text-success bold"><b>Imagick kann AVIF</b></p>';
    } else {
        echo '<p class="text-danger bold"><b>Imagick kann kein AVIF</b></p>';
    }

    $imagickVersion = Imagick::getVersion()["versionString"];
    echo "<p>Imagick Version: " . $imagickVersion . "</p>";
} else {
    echo '<p class="text-danger bold"><b>Imagick ist nicht installiert</b></p>';
    $imagickFormats = [];
}

if (function_exists('imageavif')) {
    echo '<p class="text-success bold"><b>imageavif ist verfügbar</b></p>';
} else {
    echo '<p class="text-danger bold"><b>imageavif ist nicht verfügbar</b></p>';
}

if (function_exists('imagewebp')) {
    echo '<p class="text-success bold"><b>imagewebp ist verfügbar</b></p>';
} else {
    echo '<p class="text-danger bold"><b>imagewebp ist nicht verfügbar</b></p>';
}

// Check if GD can handle webp and avif. 
// The PHP version must support avif even if Imagick is used. 
// Otherwise calling imagecreatefromstring() with an avif image from Imagick will throw an error.
if (Helper::gdSupportsWebp()) {
    echo '<p class="text-success bold"><b>GD kann WEBP</b></p>';
} else {
    echo '<p class="text-danger bold"><b>GD kann kein WEBP</b></p>';
}

if (Helper::gdSupportsAvif()) {
    echo '<p class="text-success bold"><b>GD kann AVIF</b></p>';
} else {
    echo '<p class="text-danger bold"><b>GD kann kein AVIF</b></p>';
}

if (rex_version::compare(rex::getVersion(), '5.15.0', '>=')) {
    echo '<p class="text-success bold"><b>Redaxo Version neuer als 5.15.0</b></p>';
} else {
    echo '<p class="text-danger bold"><b>Redaxo Version nicht neuer als 5.15.0</b></p>';
}

$webpPossible = Helper::webpPossible();
$avifPossible = Helper::avifPossible();

if ($webpPossible) {
    $canGenerateWebp = '<span class="text-success"><b>Ja</b></span>';
} else {
    $canGenerateWebp = '<span class="text-danger"><b>Nein</b></span>';
}

if ($avifPossible) {
    $canGenerateAvif = '<span class="text-success"><b>Ja</b></span>';
} else {
    $canGenerateAvif = '<span class="text-danger"><b>Nein</b></span>';
}

echo "<p>WEBP Ausgabe möglich: " . $canGenerateWebp . "</p>";
echo "<p>AVIF Ausgabe möglich: " . $canGenerateAvif . "</p>";



// demo images to see if codecs are installed and output is possible
echo "<h3>Demo Bilder um Konvertierung zu überprüfen:</h3>";

echo rex_view::info("Auch wenn hier ein AVIF- oder WebP-Bild angezeigt wird, kann es sein, dass die Ausgabe in diesen Formaten nicht funktioniert, wenn oben “Nein” steht. Das liegt daran, dass diese Bilder hier nicht über den Media Manager bereitgestellt werden und deshalb nicht den gleichen Einschränkungen unterliegen.");

$demo_img = rex_path::addon('media_negotiator', "data/demo.jpg");
$image = imagecreatefromjpeg($demo_img);

$image_jpeg = file_get_contents(rex_path::addon('media_negotiator', "data/demo.jpg"));
$size_jpeg = strlen($image_jpeg) / 1000;
$base64_jpeg = '<img class="img-thumbnail" src="data:image/webp;base64,' . base64_encode($image_jpeg) . '">';


if (function_exists('imagewebp')) {
    ob_start();
    imagewebp($image);
    $imageData = ob_get_contents();
    ob_end_clean();
    $size_imagewebp = strlen($imageData) / 1000;
    $img_imagewebp = '<img class="img-thumbnail" src="data:image/webp;base64,' . base64_encode($imageData) . '">';
} else {
    $size_imagewebp = 0;
    $img_imagewebp = '<p>imagewebp: nicht verfügbar</p>';
}

if (function_exists('imageavif')) {
    ob_start();
    imageavif($image);
    $imageData = ob_get_contents();
    ob_end_clean();
    $size_imageavif = strlen($imageData) / 1000;
    $img_imageavif = '<img class="img-thumbnail" src="data:image/webp;base64,' . base64_encode($imageData) . '">';
} else {
    $size_imageavif = 0;
    $img_imageavif = '<p>imageavif: nicht verfügbar</p>';
}

if (class_exists(Imagick::class)) {
    try {
        $image = new Imagick($demo_img);
        $image->setImageFormat('webp');
        $imageData = $image->getImageBlob();
        $size_imagickwebp = $image->getImageLength() / 1000;
        $img_imagickwebp = '<img class="img-thumbnail" src="data:image/webp;base64,' . base64_encode($imageData) . '">';
    } catch (Exception $e) {
        $size_imagickwebp = 0;
        $img_imagickwebp = "<p>Imagick webp: " . rex_view::error($e->getMessage()) . "</p>";
    }

    try {
        $image = new Imagick($demo_img);
        $image->setImageFormat('avif');
        $imageData = $image->getImageBlob();
        $imageDataBase64 = base64_encode($imageData);
        $size_imagickavif = $image->getImageLength() / 1000;
        $img_imagickavif = '<img class="img-thumbnail" src="data:image/webp;base64,' . base64_encode($imageData) . '">';
    } catch (Exception $e) {
        $size_imagickavif = 0;
        $img_imagickavif = "<p>Imagick avif: " . rex_view::error($e->getMessage()) . "</p>";
    }
} else {
    $size_imagickwebp = 0;
    $img_imagickwebp = "<p>Imagick webp: nicht verfügbar</p>";
    $size_imagickavif = 0;
    $img_imagickavif = "<p>Imagick avif: nicht verfügbar</p>";
}

?>

<table class="table">
    <tr>
        <th>Funktion</th>
        <th>Bild</th>
        <th>Größe</th>
        <th>Vergleich zum Original</th>
    </tr>
    <tr>
        <td>Originalbild</td>
        <td><?= $base64_jpeg ?></td>
        <td><b><?= number_format($size_jpeg, 1) ?></b> KB</td>
        <td>-</td>
    </tr>
    <tr>
        <td>imageavif</td>
        <td><?= $img_imageavif ?></td>
        <td><b><?= number_format($size_imageavif, 1) ?></b> KB</td>
        <td><b><?= number_format($size_imageavif / $size_jpeg * 100, 1) ?>%</b></td>
    </tr>
    <tr>
        <td>imagewebp</td>
        <td><?= $img_imagewebp ?></td>
        <td><b><?= number_format($size_imagewebp, 1) ?></b> KB</td>
        <td><b><?= number_format($size_imagewebp / $size_jpeg * 100, 1) ?>%</b></td>
    </tr>

    <tr>
        <td>Imagick avif</td>
        <td><?= $img_imagickavif ?></td>
        <td><b><?= number_format($size_imagickavif, 1) ?></b> KB</td>
        <td><b><?= number_format($size_imagickavif / $size_jpeg * 100, 1) ?>%</b></td>
    </tr>
    <tr>
        <td>Imagick webp</td>
        <td><?= $img_imagickwebp ?></td>
        <td><b><?= number_format($size_imagickwebp, 1) ?></b> KB</td>
        <td><b><?= number_format($size_imagickwebp / $size_jpeg * 100, 1) ?>%</b></td>
    </tr>
</table>
