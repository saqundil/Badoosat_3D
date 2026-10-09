<?php

declare(strict_types=1);

namespace App\Print;

use FPDF;

/**
 * Page geometry is identical whichever driver is in use, so the print house
 * receives the same shape of file from a shared host and from a VPS. FPDF is
 * pure PHP and needs no extension at all.
 *
 * Drivers differ only where they genuinely can: colour conversion.
 */
abstract class AbstractPrintDriver implements PrintDriverInterface
{
    /** Length of each crop mark arm, in millimetres. */
    protected const MARK_MM = 5.0;

    abstract public function isAvailable(): bool;

    abstract public function toCmyk(string $src, string $dst, string $iccPath): void;

    /** Whether toCmyk() really converts, or just hands the file through. */
    abstract public function convertsColour(): bool;

    public function name(): string
    {
        return strtolower(str_replace('Driver', '', (new \ReflectionClass($this))->getShortName()));
    }

    /**
     * Writes the artwork at its exact physical size, with crop marks at the trim
     * box and a dashed safety line $bleedMm inside it. The artwork is never
     * scaled to fill a bleed: exact dimensions matter more here than overscan,
     * and direct-to-garment printing has no trim to bleed into.
     */
    public function toPdf(string $src, string $dst, float $wCm, float $hCm, float $bleedMm): void
    {
        if (!is_file($src)) {
            throw new PrintDriverException('Artwork missing: ' . $src);
        }

        $trimW  = $wCm * 10;
        $trimH  = $hCm * 10;
        $margin = self::MARK_MM + 6.0;
        $pageW  = $trimW + 2 * $margin;
        $pageH  = $trimH + 2 * $margin;

        $pdf = new FPDF('P', 'mm', [$pageW, $pageH]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCompression(true);
        $pdf->AddPage();

        $pdf->Image($src, $margin, $margin, $trimW, $trimH, self::imageType($src));

        $this->drawCropMarks($pdf, $margin, $trimW, $trimH);

        if ($bleedMm > 0) {
            $this->drawSafetyBox($pdf, $margin + $bleedMm, $trimW - 2 * $bleedMm, $trimH - 2 * $bleedMm);
        }

        $this->drawCaption($pdf, $margin, $pageH, $wCm, $hCm, $bleedMm);

        $pdf->Output('F', $dst);

        if (!is_file($dst)) {
            throw new PrintDriverException('PDF was not written: ' . $dst);
        }
    }

    private function drawCropMarks(FPDF $pdf, float $margin, float $trimW, float $trimH): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.25);

        $gap  = 2.0;
        $mark = self::MARK_MM;
        $x0   = $margin;
        $y0   = $margin;
        $x1   = $margin + $trimW;
        $y1   = $margin + $trimH;

        foreach ([[$x0, $y0, -1, -1], [$x1, $y0, 1, -1], [$x0, $y1, -1, 1], [$x1, $y1, 1, 1]] as [$x, $y, $sx, $sy]) {
            $pdf->Line($x + $sx * $gap, $y, $x + $sx * ($gap + $mark), $y);
            $pdf->Line($x, $y + $sy * $gap, $x, $y + $sy * ($gap + $mark));
        }
    }

    private function drawSafetyBox(FPDF $pdf, float $x, float $w, float $h): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $pdf->SetDrawColor(150, 150, 150);
        $pdf->SetLineWidth(0.15);

        // FPDF has no dashed line: step along each edge instead.
        $y    = $x;
        $step = 2.0;

        for ($i = 0; $i < $w; $i += $step * 2) {
            $len = min($step, $w - $i);
            $pdf->Line($x + $i, $y, $x + $i + $len, $y);
            $pdf->Line($x + $i, $y + $h, $x + $i + $len, $y + $h);
        }

        for ($i = 0; $i < $h; $i += $step * 2) {
            $len = min($step, $h - $i);
            $pdf->Line($x, $y + $i, $x, $y + $i + $len);
            $pdf->Line($x + $w, $y + $i, $x + $w, $y + $i + $len);
        }
    }

    private function drawCaption(FPDF $pdf, float $margin, float $pageH, float $wCm, float $hCm, float $bleedMm): void
    {
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor(110, 110, 110);
        $pdf->SetXY($margin, $pageH - $margin + 1.5);
        $pdf->Cell(
            0,
            3,
            sprintf(
                'TRIM %.1f x %.1f cm  -  SAFETY %.0f mm  -  COLOUR %s  -  %s',
                $wCm,
                $hCm,
                $bleedMm,
                $this->convertsColour() ? 'CMYK' : 'RGB (convert at press)',
                strtoupper($this->name())
            ),
            0,
            0,
            'L'
        );
    }

    protected static function imageType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'JPG',
            'png'         => 'PNG',
            default       => 'PNG',
        };
    }
}
