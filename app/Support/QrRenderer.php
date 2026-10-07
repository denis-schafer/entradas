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
    public static function svg(string $payload, int $size = 400): string
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

        return (new Writer($renderer))->writeString(
            $payload,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M()
        );
    }
}