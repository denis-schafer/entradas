<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Dibuja el QR de un boleto como SVG.
 *
 * Vive aca, y no en el controlador, porque lo necesitan dos: el panel, para
 * reimprimir, y el portal, para que el comprador vea su entrada en el celu.
 *
 * Se genera en el servidor a proposito: el QR lleva la firma HMAC y el cliente
 * no necesita saber nada de ella, solo mirar el dibujo.
 */
class QrRenderer
{
    /**
     * @param  string|null  $caption  Texto que se dibuja debajo del QR (por
     *                                ejemplo el numero de la entrada). Va dentro
     *                                del propio SVG para que tambien aparezca en
     *                                el archivo que se descarga.
     */
    public static function svg(string $payload, int $size = 400, ?string $caption = null): string
    {
        /*
        | fill::uniformColor toma dos ColorInterface (background y foreground),
        | no ints. Antes pasaba tres ints: el TypeError se comia en silencio en
        | algunos paths pero en la mayoria reventaba con "rectangulo en blanco".
        |
        | writeString tiene 4 parametros: content, encoding, ecLevel, version.
        | Pasar el ecLevel en el lugar del encoding hace que el icono falle y
        | termine dibujando un rectangulo vacio.
        */
        $renderer = new ImageRenderer(
            new RendererStyle(
                max(120, min($size, 1024)),
                /*
                | Margin en modulos del QR, no en pixeles. 4 es el minimo que
                | recomienda el estandar ISO/IEC 18004 para que los lectores
                | distingan el QR del fondo. Sin esto el QR queda pegado al
                | borde y al descargarlo a PDF queda des-centrado.
                */
                4,
                null,
                null,
                Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))
            ),
            new SvgImageBackEnd
        );

        $svg = (new Writer($renderer))->writeString(
            $payload,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M()
        );

        if ($caption === null || trim($caption) === '') {
            return $svg;
        }

        return self::appendCaption($svg, max(120, min($size, 1024)), $caption);
    }

    /**
     * Agrega una franja blanca con el texto centrado debajo del QR.
     *
     * Se hace por reemplazo sobre el SVG ya generado (BaconQrCode no expone una
     * API para esto): se estira el viewBox/alto y se inserta un <rect> de fondo
     * mas un <text> antes del cierre. El codigo queda intacto arriba, asi que
     * sigue escaneando igual.
     */
    private static function appendCaption(string $svg, int $size, string $caption): string
    {
        $captionHeight = max(30, (int) round($size * 0.14));
        $totalHeight = $size + $captionHeight;

        $svg = str_replace(
            'width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'"',
            'width="'.$size.'" height="'.$totalHeight.'" viewBox="0 0 '.$size.' '.$totalHeight.'"',
            $svg
        );

        $fontSize = max(14, (int) round($captionHeight * 0.5));
        $baseline = $size + (int) round($captionHeight * 0.66);
        $text = htmlspecialchars($caption, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $label = '<rect x="0" y="'.$size.'" width="'.$size.'" height="'.$captionHeight.'" fill="#ffffff"/>'
            .'<text x="'.($size / 2).'" y="'.$baseline.'" text-anchor="middle" '
            .'font-family="Arial, Helvetica, sans-serif" font-size="'.$fontSize.'" '
            .'font-weight="bold" fill="#000000">'.$text.'</text>';

        return str_replace('</svg>', $label.'</svg>', $svg);
    }
}