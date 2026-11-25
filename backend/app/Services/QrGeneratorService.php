<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class QrGeneratorService
{
    /**
     * URL truy xuất công khai của một token đã có. Không sinh qr_token và không đọc database.
     */
    public function traceUrl(string $qrToken): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/trace/'.rawurlencode($qrToken);
    }

    /**
     * Chuyển một URL thành SVG. Không ghi file, không dùng GD hoặc Imagick.
     */
    public function svgForUrl(string $url): string
    {
        if (trim($url) === '') {
            throw new InvalidArgumentException('URL dùng để tạo QR không được để trống.');
        }

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd()
        );
        $svg = (new Writer($renderer))->writeString($url);
        $description = '<desc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</desc>';

        if (preg_match('/<svg\b[^>]*>/', $svg) === 1) {
            return (string) preg_replace('/<svg\b[^>]*>/', '$0'.$description, $svg, 1);
        }

        return $svg;
    }

    /**
     * SVG của QR trỏ tới URL truy xuất của token đã có trên lô.
     */
    public function svgForToken(string $qrToken): string
    {
        return $this->svgForUrl($this->traceUrl($qrToken));
    }
}
