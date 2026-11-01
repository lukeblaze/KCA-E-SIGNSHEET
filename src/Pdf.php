<?php
declare(strict_types=1);
namespace Kca;

/** Minimal dependency-free PDF 1.4 writer: Helvetica text, rects, lines, PNG images with alpha. A4, top-left origin. */
final class Pdf
{
    public const W = 595.28;
    public const H = 841.89;

    private const WR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const WB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    /** @var list<array{c:string,img:array<string,int>}> */
    private array $pages = [];
    private int $cur = -1;
    private array $objs = [];
    private int $nextId = 5;
    private array $imgCache = [];

    public function addPage(): int
    {
        $this->pages[] = ['c' => '', 'img' => []];
        return $this->cur = count($this->pages) - 1;
    }
    public function pageCount(): int { return count($this->pages); }
    public function gotoPage(int $i): void { $this->cur = $i; }

    private static function col(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }
    private function c(string $s): void { $this->pages[$this->cur]['c'] .= $s . "\n"; }
    private static function y(float $y): float { return self::H - $y; }

    private static function enc(string $s): string
    {
        $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    public function width(string $s, bool $bold = false, float $size = 10): float
    {
        $t = $bold ? self::WB : self::WR;
        $w = 0;
        foreach (str_split(mb_convert_encoding($s, 'Windows-1252', 'UTF-8')) as $ch) {
            $o = ord($ch) - 32;
            $w += $t[$o] ?? 556;
        }
        return $w * $size / 1000;
    }

    /** Trim with an ellipsis so text never overflows a column. */
    public function fit(string $s, float $maxW, bool $bold = false, float $size = 10): string
    {
        if ($this->width($s, $bold, $size) <= $maxW) return $s;
        while ($s !== '' && $this->width($s . '...', $bold, $size) > $maxW) $s = mb_substr($s, 0, -1);
        return $s . '...';
    }

    /** Word-wrap into lines no wider than $maxW. @return list<string> */
    public function wrap(string $s, float $maxW, bool $bold = false, float $size = 10): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if ($this->width($try, $bold, $size) <= $maxW || $line === '') $line = $try;
            else { $lines[] = $line; $line = $w; }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    /** $y is the text baseline measured from the top. $align: L | R | C (x is then the right edge / centre). */
    public function text(float $x, float $y, string $s, string $color = '000000', bool $bold = false, float $size = 10, string $align = 'L'): void
    {
        if ($align !== 'L') {
            $w = $this->width($s, $bold, $size);
            $x = $align === 'R' ? $x - $w : $x - $w / 2;
        }
        $this->c(sprintf('BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', self::col($color), $bold ? 'F2' : 'F1', $size, $x, self::y($y), self::enc($s)));
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $lw = 0.6): void
    {
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $pre = ($fill !== null ? self::col($fill) . ' rg ' : '') . ($stroke !== null ? self::col($stroke) . ' RG ' . sprintf('%.2F w ', $lw) : '');
        $this->c(sprintf('%s%.2F %.2F %.2F %.2F re %s', $pre, $x, self::y($y + $h), $w, $h, $op));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '999999', float $lw = 0.5): void
    {
        $this->c(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::col($color), $lw, $x1, self::y($y1), $x2, self::y($y2)));
    }

    /** Draw a PNG scaled to fit (and centred in) the given box, keeping its aspect ratio. */
    public function imageFit(string $png, float $x, float $y, float $w, float $h): void
    {
        [$id, $iw, $ih] = $this->image($png);
        $scale = min($w / $iw, $h / $ih);
        $dw = $iw * $scale; $dh = $ih * $scale;
        $name = 'Im' . $id;
        $this->pages[$this->cur]['img'][$name] = $id;
        $this->c(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $dw, $dh, $x + ($w - $dw) / 2, self::y($y + $h - ($h - $dh) / 2), $name));
    }

    /** @return array{0:int,1:int,2:int} object id, width, height */
    private function image(string $png): array
    {
        $key = sha1($png);
        if (isset($this->imgCache[$key])) return $this->imgCache[$key];
        $im = imagecreatefromstring($png);
        if (!$im) throw new \RuntimeException('Bad image');
        imagepalettetotruecolor($im);
        $w = imagesx($im); $h = imagesy($im);
        $rgb = ''; $alpha = '';
        for ($yy = 0; $yy < $h; $yy++) {
            for ($xx = 0; $xx < $w; $xx++) {
                $p = imagecolorat($im, $xx, $yy);
                $a = ($p >> 24) & 0x7F;
                $rgb .= chr(($p >> 16) & 255) . chr(($p >> 8) & 255) . chr($p & 255);
                $alpha .= chr(255 - (int)round($a * 255 / 127));
            }
        }
        $maskId = $this->nextId++;
        $imgId = $this->nextId++;
        $z = fn(string $d) => gzcompress($d, 9);
        $this->objs[$maskId] = $this->stream("/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode", $z($alpha));
        $this->objs[$imgId] = $this->stream("/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /SMask $maskId 0 R /Filter /FlateDecode", $z($rgb));
        return $this->imgCache[$key] = [$imgId, $w, $h];
    }

    private function stream(string $dict, string $data): string
    {
        return "<< $dict /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    public function output(array $info = []): string
    {
        $objs = $this->objs;
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = $this->nextId;
        foreach ($this->pages as $pg) {
            $cid = $next++; $pid = $next++;
            $objs[$cid] = $this->stream('/Filter /FlateDecode', gzcompress($pg['c'], 9));
            $xo = '';
            foreach ($pg['img'] as $n => $id) $xo .= "/$n $id 0 R ";
            $objs[$pid] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s>> >> >>', self::W, self::H, $cid, $xo);
            $kids[] = "$pid 0 R";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $infoId = $next++;
        $d = 'D:' . gmdate('YmdHis') . "+00'00'";
        $f = fn(string $s) => '(' . self::enc($s) . ')';
        $objs[$infoId] = '<< /Title ' . $f($info['title'] ?? 'KCA e-Sign Sheet') . ' /Author ' . $f($info['author'] ?? 'KCA University') . ' /Subject ' . $f($info['subject'] ?? '')
            . ' /Keywords ' . $f($info['keywords'] ?? '') . ' /Creator ' . $f('KCA e-Sign Sheet') . ' /Producer ' . $f('KCA e-Sign Sheet PDF writer') . " /CreationDate ($d) >>";
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $id => $body) {
            $off[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $out .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }
}
