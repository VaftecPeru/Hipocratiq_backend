<?php

declare(strict_types=1);

namespace Tests\Feature;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class DependencySecurityRegressionTest extends TestCase
{
    public function test_safe_synthetic_xlsx_round_trip_remains_compatible(): void
    {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('A1', 'Registro sintético');
        $sheet->setCellValue('B1', 42);

        $path = tempnam(sys_get_temp_dir(), 'hipocratiq_xlsx_');
        self::assertNotFalse($path);

        try {
            $writer = new XlsxWriter($workbook);
            $writer->save($path);
            $reader = new XlsxReader();
            $readBack = $reader->load($path);
            try {
                self::assertSame('Registro sintético', $readBack->getActiveSheet()->getCell('A1')->getValue());
                self::assertSame(42, $readBack->getActiveSheet()->getCell('B1')->getValue());
            } finally {
                $readBack->disconnectWorksheets();
            }
        } finally {
            $workbook->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_mime_email_with_synthetic_attachment_serializes_safely(): void
    {
        $email = new Email();
        $email->from('qa@example.test')
            ->to('test@example.test')
            ->subject('Prueba de correo')
            ->text('Contenido de prueba, sin datos clínicos')
            ->attach('contenido sintético', 'reporte.txt', 'text/plain');

        $message = $email->toString();

        self::assertStringContainsString('reporte.txt', $message);
        self::assertStringContainsString('Content-Type:', $message);
        self::assertStringContainsString('qa@example.test', $message);
        self::assertStringNotContainsString('Bcc:', $message);
    }
}
