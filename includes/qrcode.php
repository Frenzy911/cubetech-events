<?php
/**
 * Small, dependency-free QR Code generator (byte mode, error correction level M,
 * versions 1-10 = up to 213 bytes). Outputs an SVG, so it works offline and needs
 * no PHP extensions.
 *
 * Algorithm follows ISO/IEC 18004 as implemented in Project Nayuki's
 * "QR Code generator library" (MIT License).
 *
 * Usage:  echo QrCode::svg('CDM-7K3P-9QXW-M2RT');
 */
final class QrCode
{
    // Error correction level M, indexed by version (index 0 unused)
    private const ECC_CODEWORDS_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const NUM_ECC_BLOCKS          = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const FORMAT_BITS_LEVEL_M     = 0;
    private const MAX_VERSION             = 10;

    private int $version;
    private int $size;
    /** @var array<int, array<int, bool>> [y][x] */
    private array $modules = [];
    /** @var array<int, array<int, bool>> [y][x] */
    private array $isFunction = [];

    /** @return array<int, array<int, bool>> rows of dark (true) / light (false) modules */
    public static function matrix(string $text, ?int $forceMask = null): array
    {
        return (new self($text, $forceMask))->modules;
    }

    public static function svg(string $text, int $border = 4, string $class = 'qr-code'): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $dim = $size + $border * 2;
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $border) . ' ' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" class="' . htmlspecialchars($class) . '"'
             . ' shape-rendering="crispEdges" role="img" aria-label="QR code for ' . htmlspecialchars($text) . '">'
             . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
             . '<path d="' . $path . '" fill="#000000"/></svg>';
    }

    private function __construct(string $text, ?int $forceMask = null)
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);

        // 1) Smallest version that fits
        $version = 1;
        while (true) {
            if ($version > self::MAX_VERSION) {
                throw new InvalidArgumentException('Text is too long for this QR generator.');
            }
            $countBits = $version <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * $len <= self::numDataCodewords($version) * 8) {
                break;
            }
            $version++;
        }
        $this->version = $version;
        $this->size = $version * 4 + 17;

        // 2) Data bits: mode indicator (byte = 0100), length, bytes, terminator, padding
        $capacityBits = self::numDataCodewords($version) * 8;
        $bits = [];
        self::appendBits($bits, 0x4, 4);
        self::appendBits($bits, $len, $countBits);
        foreach ($data as $byte) {
            self::appendBits($bits, $byte, 8);
        }
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $codewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        // 3) Draw function patterns, then the data + error correction codewords
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($codewords));

        // 4) Pick the mask with the lowest penalty score
        $bestMask = 0;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penaltyScore();
            if ($penalty < $minPenalty) {
                $bestMask = $mask;
                $minPenalty = $penalty;
            }
            $this->applyMask($mask); // XOR again = undo
        }
        if ($forceMask !== null) {
            $bestMask = $forceMask;
        }
        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
    }

    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function bit(int $value, int $i): bool
    {
        return (($value >> $i) & 1) !== 0;
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_CODEWORDS_PER_BLOCK[$ver] * self::NUM_ECC_BLOCKS[$ver];
    }

    private function setFunctionModule(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        // Timing patterns
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }
        // Finder patterns (3 corners) with separators
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);
        // Alignment patterns
        $positions = $this->alignmentPatternPositions();
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $overlapsFinder = ($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0);
                if (!$overlapsFinder) {
                    $this->drawAlignmentPattern($positions[$i], $positions[$j]);
                }
            }
        }
        // Reserve format + version areas
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinderPattern(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunctionModule($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignmentPattern(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function alignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = (int) (ceil(($this->version * 4 + 4) / ($numAlign * 2 - 2)) * 2);
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS_LEVEL_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        // First copy (around the top-left finder)
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, self::bit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::bit($bits, 6));
        $this->setFunctionModule(8, 8, self::bit($bits, 7));
        $this->setFunctionModule(7, 8, self::bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::bit($bits, $i));
        }
        // Second copy (top-right and bottom-left)
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::bit($bits, $i));
        }
        $this->setFunctionModule(8, $this->size - 8, true); // always-dark module
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = self::bit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $dark);
            $this->setFunctionModule($b, $a, $dark);
        }
    }

    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::NUM_ECC_BLOCKS[$ver];
        $eccLen = self::ECC_CODEWORDS_PER_BLOCK[$ver];
        $rawCodewords = intdiv(self::numRawDataModules($ver), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::reedSolomonDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $datLen = $shortBlockLen - $eccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::reedSolomonRemainder($dat, $divisor);
            if ($i < $numShortBlocks) {
                $dat[] = 0; // placeholder, skipped when interleaving
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $eccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMultiply($coef, $factor);
            }
        }
        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private function drawCodewords(array $data): void
    {
        $totalBits = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing column
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $totalBits) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Pushes a run length onto the 7-entry run history (newest first). */
    private function finderAddHistory(int $runLength, array &$history): void
    {
        if ($history[0] === 0) {
            $runLength += $this->size; // light quiet zone before the first run
        }
        array_pop($history);
        array_unshift($history, $runLength);
    }

    /** Counts finder-like patterns (dark:light:dark:light:dark = 1:1:3:1:1 with 4 light on one side). */
    private function finderCountPatterns(array $h): int
    {
        $n = $h[1];
        $core = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
        return ($core && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0)
             + ($core && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }

    private function penaltyScore(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;

        // N1 (runs of 5+ same colour) and N3 (finder-like 1:1:3:1:1 patterns at any scale),
        // for every row and every column - same method as the reference implementation.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $runColor = false;
                $runLen = 0;
                $history = [0, 0, 0, 0, 0, 0, 0];
                for ($b = 0; $b < $n; $b++) {
                    $color = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    if ($color === $runColor) {
                        $runLen++;
                        if ($runLen === 5) {
                            $score += 3;
                        } elseif ($runLen > 5) {
                            $score++;
                        }
                    } else {
                        $this->finderAddHistory($runLen, $history);
                        if (!$runColor) {
                            $score += $this->finderCountPatterns($history) * 40;
                        }
                        $runColor = $color;
                        $runLen = 1;
                    }
                }
                // Terminate the line (the quiet zone outside the symbol is light)
                if ($runColor) {
                    $this->finderAddHistory($runLen, $history);
                    $runLen = 0;
                }
                $this->finderAddHistory($runLen + $n, $history);
                $score += $this->finderCountPatterns($history) * 40;
            }
        }
        // N2: 2x2 blocks of the same colour
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // N4: balance of dark and light modules
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $c) {
                if ($c) {
                    $dark++;
                }
            }
        }
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        $score += max(0, $k) * 10;

        return $score;
    }
}
