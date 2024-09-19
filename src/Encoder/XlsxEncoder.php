<?php

declare(strict_types=1);

namespace IAmBod\Serializer\Xlsx\Encoder;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

class XlsxEncoder implements EncoderInterface, DecoderInterface
{
    public const FORMAT = 'xlsx';
    public const KEY_SEPARATOR_KEY = 'xlsx_key_separator';
    public const HEADERS_KEY = 'xlsx_headers';
    public const ESCAPE_FORMULAS_KEY = 'xlsx_escape_formulas';
    public const NO_HEADERS_KEY = 'no_headers';
    public const OUTPUT_UTF8_BOM_KEY = 'output_utf8_bom';
    public const AS_COLLECTION_KEY = 'as_collection';

    private const UTF8_BOM = "\xEF\xBB\xBF";

    private const FORMULAS_START_CHARACTERS = ['=', '-', '+', '@'];
    private $defaultContext = [
        self::KEY_SEPARATOR_KEY => '.',
        self::HEADERS_KEY => [],
        self::ESCAPE_FORMULAS_KEY => false,
        self::NO_HEADERS_KEY => false,
        self::OUTPUT_UTF8_BOM_KEY => false,
        self::AS_COLLECTION_KEY => true
    ];

    public function __construct(array $defaultContext = [])
    {
        $this->defaultContext = array_merge($this->defaultContext, $defaultContext);
    }

    public function encode($data, $format, array $context = []): string
    {
        if (!is_iterable($data)) {
            $data = [[$data]];
        } elseif (empty($data)) {
            $data = [[]];
        } else {
            // Sequential arrays of arrays are considered as collections
            $i = 0;
            foreach ($data as $key => $value) {
                if ($i !== $key || !\is_array($value)) {
                    $data = [$data];
                    break;
                }

                ++$i;
            }
        }

        [$keySeparator, $headers, $escapeFormulas, $noHeaders, $outputBom] = $this->getXlsxOptions($context);

        foreach ($data as &$value) {
            $flattened = [];
            $this->flatten($value, $flattened, $keySeparator, '', $escapeFormulas);
            $value = $flattened;
        }

        unset($value);

        $spreadsheet = new Spreadsheet();

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setUseDiskCaching(true);

        $worksheet = $spreadsheet->getActiveSheet();
        $rowIndex = 1;

        if (empty($headers)) {
            $headers = $this->extractHeaders($data);
        }

        if (!$noHeaders) {
            $columnIndex = 1;

            foreach ($headers as $header) {
                $worksheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $header);
            }

            $rowIndex++;
        }

        if ($headers !== array_values($headers)) {
            $headers = array_keys($headers);
        }

        $headers = array_fill_keys($headers, null);

        foreach ($data as $row) {
            $cells = array_intersect_key($row, $headers);
            $columnIndex = 1;

            foreach ($headers as $header => $fakeValue) {
                $worksheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $cells[$header] ?? null);
            }

            $rowIndex++;
        }

        $tempFileName = tempnam(File::sysGetTempDir(), 'symfony-serializer-xlsx-');
        $writer->save($tempFileName);

        $handle = fopen($tempFileName, 'rb+');
        $value = stream_get_contents($handle);
        fclose($handle);

        unlink($tempFileName);

        if ($outputBom) {
            if (!preg_match('//u', $value)) {
                throw new UnexpectedValueException('You are trying to add a UTF-8 BOM to a non UTF-8 text.');
            }

            $value = self::UTF8_BOM.$value;
        }

        return $value;
    }

    public function supportsEncoding($format): bool
    {
        return self::FORMAT === $format;
    }

    public function decode($data, $format, array $context = []): array
    {
        if (empty($data)) {
            return [];
        }

        $tempFileName = tempnam(File::sysGetTempDir(), 'symfony-serializer-xlsx-');
        $handle = fopen($tempFileName, 'wb+');
        fwrite($handle, $data);
        fclose($handle);

        [, $headers, , $noHeaders, , $asCollection] = $this->getXlsxOptions($context);

        $reader = new Xlsx();
        $spreadSheet = $reader->load($tempFileName);

        unlink($tempFileName);

        $result = $spreadSheet->getActiveSheet()->toArray();

        if ($noHeaders) {
            $headers = [];
            $firstRowCount = count($result[0]);

            for ($i = 0; $i < $firstRowCount; $i++) {
                $headers[] = $i;
            }
        } elseif (empty($headers) && isset($result[0])) {
            $headers = array_shift($result);
        }

        $headerCount = count($headers);

        $result = array_map(static function (array $row) use ($headers, $headerCount) {
            $rowCount = count($row);

            if ($headerCount < $rowCount) {
                $row = array_slice($row, 0, $headerCount);
            }

            if ($headerCount > $rowCount) {
                $row = array_pad($row, $headerCount, null);
            }

            return array_combine($headers, $row);
        }, $result);

        if ($asCollection) {
            return $result;
        }

        if (empty($result) || isset($result[1])) {
            return $result;
        }

        return $result[0];
    }

    public function supportsDecoding($format): bool
    {
        return self::FORMAT === $format;
    }

    /**
     * Flattens an array and generates keys including the path.
     */
    private function flatten(iterable $array, array &$result, string $keySeparator, string $parentKey = '', bool $escapeFormulas = false): void
    {
        foreach ($array as $key => $value) {
            if (is_iterable($value)) {
                $this->flatten($value, $result, $keySeparator, $parentKey.$key.$keySeparator, $escapeFormulas);
            } else {
                if ($escapeFormulas && \in_array(substr((string) $value, 0, 1), self::FORMULAS_START_CHARACTERS, true)) {
                    $result[$parentKey.$key] = "\t".$value;
                } else {
                    // Ensures an actual value is used when dealing with true and false
                    $result[$parentKey.$key] = false === $value ? 0 : (true === $value ? 1 : $value);
                }
            }
        }
    }

    private function getXlsxOptions(array $context): array
    {
        $keySeparator = $context[self::KEY_SEPARATOR_KEY] ?? $this->defaultContext[self::KEY_SEPARATOR_KEY];
        $headers = $context[self::HEADERS_KEY] ?? $this->defaultContext[self::HEADERS_KEY];
        $escapeFormulas = $context[self::ESCAPE_FORMULAS_KEY] ?? $this->defaultContext[self::ESCAPE_FORMULAS_KEY];
        $noHeaders = $context[self::NO_HEADERS_KEY] ?? $this->defaultContext[self::NO_HEADERS_KEY];
        $outputBom = $context[self::OUTPUT_UTF8_BOM_KEY] ?? $this->defaultContext[self::OUTPUT_UTF8_BOM_KEY];
        $asCollection = $context[self::AS_COLLECTION_KEY] ?? $this->defaultContext[self::AS_COLLECTION_KEY];

        return [$keySeparator, $headers, $escapeFormulas, $noHeaders, $outputBom, $asCollection];
    }

    /**
     * @return string[]
     */
    private function extractHeaders(iterable $data): array
    {
        $headers = [];
        $flippedHeaders = [];

        foreach ($data as $row) {
            $previousHeader = null;

            foreach ($row as $header => $_) {
                if (isset($flippedHeaders[$header])) {
                    $previousHeader = $header;
                    continue;
                }

                if (null === $previousHeader) {
                    $n = \count($headers);
                } else {
                    $n = $flippedHeaders[$previousHeader] + 1;

                    for ($j = \count($headers); $j > $n; --$j) {
                        ++$flippedHeaders[$headers[$j] = $headers[$j - 1]];
                    }
                }

                $headers[$n] = $header;
                $flippedHeaders[$header] = $n;
                $previousHeader = $header;
            }
        }

        return $headers;
    }
}
