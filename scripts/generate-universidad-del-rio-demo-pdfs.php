<?php

declare(strict_types=1);

$documents = [
    'calendario-admision-2027-v1.pdf' => [
        'Calendario de admisión 2027',
        'Escenario ficticio: Universidad del Río',
        'Inicio de postulaciones: 4 de enero de 2027.',
        'Cierre de postulaciones: 29 de enero de 2027.',
        'Publicación de resultados: 12 de febrero de 2027.',
        'Matrícula de seleccionados: 15 al 19 de febrero de 2027.',
        'Estas fechas son ficticias y se usan sólo en la demostración de Acervo.',
    ],
    'lineamientos-atencion-postulantes-v1.pdf' => [
        'Lineamientos de atención a postulantes',
        'Escenario ficticio: Universidad del Río',
        'Entregar información clara, consistente y respetuosa.',
        'Registrar las consultas que requieran seguimiento.',
        'No solicitar antecedentes personales por canales no autorizados.',
        'Derivar casos especiales a la Dirección de Admisión.',
    ],
    'ficha-ingenieria-civil-informatica-v1.pdf' => [
        'Ficha de Ingeniería Civil en Informática',
        'Escenario ficticio: Universidad del Río',
        'Duración referencial: diez semestres.',
        'Modalidad ficticia: presencial.',
        'Áreas formativas: programación, sistemas, datos y gestión tecnológica.',
        'La información de esta ficha no corresponde a una oferta académica real.',
    ],
    'ficha-enfermeria-v1.pdf' => [
        'Ficha de Enfermería',
        'Escenario ficticio: Universidad del Río',
        'Duración referencial: diez semestres.',
        'Modalidad ficticia: presencial.',
        'Áreas formativas: cuidado integral, salud comunitaria y gestión clínica.',
        'La información de esta ficha no corresponde a una oferta académica real.',
    ],
    'requisitos-ingreso-especial-2027-v1.pdf' => [
        'Requisitos de ingreso especial 2027 — Versión 1',
        'Escenario ficticio: Universidad del Río',
        'Antecedentes iniciales del postulante:',
        '• Formulario de postulación completo.',
        '• Documento de identidad ficticio para la demostración.',
        '• Carta de motivación.',
        '• Certificado de estudios previos cuando corresponda.',
    ],
    'requisitos-ingreso-especial-2027-v2.pdf' => [
        'Requisitos de ingreso especial 2027 — Versión 2',
        'Escenario ficticio: Universidad del Río',
        'Mantiene los antecedentes de la versión inicial.',
        'Para la vía de traslado se incorpora:',
        '• Certificado de avance curricular emitido por la institución de origen.',
        'Este material es ficticio y se utiliza sólo para demostrar Acervo.',
    ],
    'preguntas-frecuentes-admision.pdf' => [
        'Preguntas frecuentes de admisión',
        'Archivo reservado para carga manual durante la demostración.',
        '¿Dónde reviso el calendario? En la carpeta Lineamientos y calendario.',
        '¿Dónde reviso las carreras? En la carpeta Oferta académica.',
        '¿La información es oficial? No. Todo el contenido es ficticio.',
    ],
    'requisitos-ingreso-especial-2027-v3.pdf' => [
        'Requisitos de ingreso especial 2027 — Versión 3',
        'Archivo reservado para carga manual durante la demostración.',
        'Aclara el requisito incorporado en la versión 2:',
        '• El certificado de avance curricular debe indicar las asignaturas',
        '  aprobadas y la carga horaria correspondiente.',
        'Este material es ficticio y se utiliza sólo para demostrar Acervo.',
    ],
];

$target = dirname(__DIR__).'/resources/demo/universidad-del-rio';
if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
    throw new RuntimeException("Could not create {$target}");
}

foreach ($documents as $filename => $lines) {
    file_put_contents($target.'/'.$filename, buildPdf([
        'Material ficticio para demostración',
        ...$lines,
    ]));
}

/** @param list<string> $lines */
function buildPdf(array $lines): string
{
    $commands = ['BT', '/F1 11 Tf', '50 790 Td'];
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $commands[] = '0 -24 Td';
        }
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded ?: $line);
        $commands[] = "({$escaped}) Tj";
    }
    $commands[] = 'ET';
    $stream = implode("\n", $commands)."\n";

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
    $pdf .= "0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xref}\n%%EOF\n";

    return $pdf;
}
