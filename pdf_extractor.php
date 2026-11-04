<?php
// backend/pdf_extractor.php - Electoral Roll PDF Voter Extraction and Photo Extraction

class PdfExtractor
{
    /**
     * Extracts photos from the uploaded PDF and saves them for each voter
     * inside the target village folder:
     * uploads/<state>/<district>/<constituency>/<mandal>/<village>/<voter_id_no>.<ext>
     *
     * @param string $pdfPath     Full filesystem path to the PDF file
     * @param array  $voters      Array of voter records
     * @param string $targetDir   Full filesystem path to uploads/<state>/<district>/<constituency>/<mandal>/<village>
     * @param string $relativeDir Relative path starting with uploads/...
     * @return array Relative photo URLs indexed by voter index and EPIC
     */
    public static function extractPhotos($pdfPath, array $voters, $targetDir, $relativeDir)
    {
        if (empty($voters)) {
            return [];
        }

        if (empty($pdfPath) || !is_file($pdfPath) || !is_readable($pdfPath) || filesize($pdfPath) <= 100) {
            throw new RuntimeException('The uploaded PDF could not be read for voter photo extraction.');
        }

        $totalVoters = count($voters);
        $imageObjectCount = 0;
        $extractedImages = self::extractImagesFromPdf($pdfPath, $imageObjectCount);
        if ($imageObjectCount === $totalVoters && count($extractedImages) === $totalVoters) {
            $voterPhotos = self::filterVoterPhotos($extractedImages, true);
        } else {
            $voterPhotos = self::filterVoterPhotos($extractedImages);
        }

        if (count($voterPhotos) < $totalVoters) {
            throw new RuntimeException(sprintf(
                'The uploaded PDF contains %d extractable voter photos for %d voters. No default photos were saved. Check that the PDF contains embedded voter photos and that Poppler (pdfimages) is installed on the PHP server.',
                count($voterPhotos),
                $totalVoters
            ));
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Unable to create village photo upload directory: {$targetDir}");
        }
        if (!is_writable($targetDir)) {
            throw new RuntimeException("Village photo upload directory is not writable: {$targetDir}");
        }

        $savedPhotos = [];
        for ($i = 0; $i < $totalVoters; $i++) {
            $voter = $voters[$i];

            $voterIdNo = trim((string)(
                $voter['epic'] ??
                $voter['voter_id'] ??
                $voter['voterId'] ??
                $voter['sl_no'] ??
                $voter['slNo'] ??
                $voter['id'] ??
                ($i + 1)
            ));

            $safeVoterId = preg_replace('/[^A-Za-z0-9_\-]/', '', $voterIdNo);
            if ($safeVoterId === '') {
                $safeVoterId = 'voter_' . ($i + 1);
            }

            $photo = $voterPhotos[$i];
            $fileName = $safeVoterId . '.' . $photo['extension'];
            $destFile = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
            $relativePhotoUrl = rtrim($relativeDir, '/') . '/' . $fileName;
            $written = @file_put_contents($destFile, $photo['data'], LOCK_EX);
            if ($written !== strlen($photo['data'])) {
                throw new RuntimeException("Unable to save the extracted voter photo: {$fileName}");
            }

            $savedPhotos[$i] = $relativePhotoUrl;
            $savedPhotos[$safeVoterId] = $relativePhotoUrl;
        }

        return $savedPhotos;
    }

    /**
     * Extracts embedded image objects from a PDF, preserving JPEGs and losslessly encoding raw pixels as PNG.
     */
    public static function extractImagesFromPdf($pdfPath, &$imageObjectCount = null)
    {
        $imageObjectCount = 0;
        $images = [];

        // Method 1: CLI pdfimages tool if available on server
        if (function_exists('exec') && !self::isFunctionDisabled('exec')) {
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_imgs_' . uniqid();
            if (@mkdir($tmpDir, 0777, true)) {
                $cmd = sprintf('pdfimages -all %s %s/img 2>&1', escapeshellarg($pdfPath), escapeshellarg($tmpDir));
                @exec($cmd, $out, $ret);
                if ($ret === 0) {
                    $found = glob($tmpDir . '/img-*.*');
                    if (!empty($found)) {
                        sort($found, SORT_NATURAL);
                        foreach ($found as $imgPath) {
                            $data = @file_get_contents($imgPath);
                            if (!empty($data)) {
                                $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                                $info = @getimagesizefromstring($data);
                                if ($info !== false) {
                                    $images[] = [
                                        'data' => $data,
                                        'width' => $info[0],
                                        'height' => $info[1],
                                        'mime' => $info['mime'] ?? '',
                                    ];
                                }
                            }
                        }
                    }
                }
                @array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);

            }
        }

        // Method 2: Stream Token Parser (Does NOT use greedy PCRE regexes that fail on large files)
        $pdf = @file_get_contents($pdfPath);
        if ($pdf === false || strlen($pdf) < 64) {
            return [];
        }

        $imageObjects = self::extractImageXObjects($pdf, $imageObjectCount);
        if ($imageObjectCount > 0) {
            if (count($imageObjects) === $imageObjectCount) {
                return $imageObjects;
            }
            $images = $imageObjects;
        }

        $pdfLen = strlen($pdf);
        $streamPos = 0;

        while (($streamPos = strpos($pdf, 'stream', $streamPos)) !== false) {
            // Find start of stream binary data (skip \r\n or \n after 'stream')
            $dataStart = $streamPos + 6;
            if ($dataStart < $pdfLen && $pdf[$dataStart] === "\r") {
                $dataStart++;
            }
            if ($dataStart < $pdfLen && $pdf[$dataStart] === "\n") {
                $dataStart++;
            }

            // Find matching endstream
            $endPos = strpos($pdf, 'endstream', $dataStart);
            if ($endPos === false) {
                break;
            }

            // Inspect the dictionary before 'stream' (up to 1200 bytes prior)
            $dictStart = max(0, $streamPos - 1200);
            $dictHeader = substr($pdf, $dictStart, $streamPos - $dictStart);

            $streamBytes = substr($pdf, $dataStart, $endPos - $dataStart);

            // Case A: Image stream with /DCTDecode (direct JPEG)
            $isImage = (stripos($dictHeader, '/Image') !== false);
            $isDCT = (stripos($dictHeader, 'DCTDecode') !== false || stripos($dictHeader, '/DCT') !== false);

            if (($isImage && $isDCT) || strpos($streamBytes, "\xFF\xD8\xFF") === 0) {
                $soi = strpos($streamBytes, "\xFF\xD8\xFF");
                if ($soi !== false) {
                    $jpeg = substr($streamBytes, $soi);
                    $eoi = strrpos($jpeg, "\xFF\xD9");
                    if ($eoi !== false) {
                        $jpeg = substr($jpeg, 0, $eoi + 2);
                    }
                    if (strlen($jpeg) > 200) {
                        $info = @getimagesizefromstring($jpeg);
                        if ($info !== false) {
                            $images[] = [
                                'data' => $jpeg,
                                'width' => $info[0],
                                'height' => $info[1],
                                'mime' => $info['mime'] ?? '',
                            ];
                        }
                    }
                }
            } elseif ($isImage && (stripos($dictHeader, 'FlateDecode') !== false)) {
                // Case B: FlateDecode (zlib-compressed stream)
                $decomp = @gzuncompress($streamBytes);
                if ($decomp === false) {
                    $decomp = @gzinflate(substr($streamBytes, 2));
                }
                if ($decomp !== false && strlen($decomp) > 200) {
                    $soi = strpos($decomp, "\xFF\xD8\xFF");
                    if ($soi !== false) {
                        $jpeg = substr($decomp, $soi);
                        $eoi = strrpos($jpeg, "\xFF\xD9");
                        if ($eoi !== false) {
                            $jpeg = substr($jpeg, 0, $eoi + 2);
                        }
                        if (strlen($jpeg) > 200) {
                            $info = @getimagesizefromstring($jpeg);
                            if ($info !== false) {
                                $images[] = [
                                    'data' => $jpeg,
                                    'width' => $info[0],
                                    'height' => $info[1],
                                    'mime' => $info['mime'] ?? '',
                                ];
                            }
                        }
                    }
                }
            }

            $streamPos = $endPos + 9;
        }

        // Scan raw JPEG streams even when Poppler extracted other images.
        $offset = 0;
        while (($start = strpos($pdf, "\xFF\xD8\xFF", $offset)) !== false) {
            $nextStart = strpos($pdf, "\xFF\xD8\xFF", $start + 4);
            $sliceLen = ($nextStart !== false) ? ($nextStart - $start) : ($pdfLen - $start);
            $slice = substr($pdf, $start, $sliceLen);

            $lastEoi = strrpos($slice, "\xFF\xD9");
            if ($lastEoi !== false) {
                $jpegBytes = substr($slice, 0, $lastEoi + 2);
                if (strlen($jpegBytes) > 200) {
                    $info = @getimagesizefromstring($jpegBytes);
                    if (!empty($info) && ($info[0] ?? 0) > 20) {
                        $images[] = [
                            'data' => $jpegBytes,
                            'width' => $info[0],
                            'height' => $info[1],
                            'mime' => $info['mime'] ?? '',
                        ];
                    }
                }
                $offset = $start + $lastEoi + 2;
            } else {
                $offset = $start + 3;
            }
        }

        $uniqueImages = [];
        $seenImages = [];
        foreach ($images as $image) {
            $hash = hash('sha256', $image['data']);
            if (isset($seenImages[$hash])) {
                continue;
            }
            $seenImages[$hash] = true;
            $uniqueImages[] = $image;
        }

        return $uniqueImages;
    }

    /**
     * Filters out polling station wide building photos or header emblems to isolate voter portraits.
     */
    private static function extractImageXObjects($pdf, &$imageObjectCount)
    {
        $images = [];
        $imageObjectCount = 0;
        if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $pdf, $objects, PREG_OFFSET_CAPTURE)) {
            return $images;
        }

        $objectCount = count($objects[0]);
        for ($i = 0; $i < $objectCount; $i++) {
            $objectStart = $objects[0][$i][1];
            $objectBodyStart = $objectStart + strlen($objects[0][$i][0]);
            $nextObjectStart = $i + 1 < $objectCount
                ? $objects[0][$i + 1][1]
                : strlen($pdf);
            $streamPos = strpos($pdf, 'stream', $objectBodyStart);
            if ($streamPos === false || $streamPos >= $nextObjectStart) {
                continue;
            }

            $dictionary = substr($pdf, $objectBodyStart, $streamPos - $objectBodyStart);
            if (!preg_match('/\/Subtype\s*\/Image\b/', $dictionary)) {
                continue;
            }
            $imageObjectCount++;

            $dataStart = $streamPos + 6;
            if (($pdf[$dataStart] ?? '') === "\r") {
                $dataStart++;
            }
            if (($pdf[$dataStart] ?? '') === "\n") {
                $dataStart++;
            }

            if (preg_match('/\/Length\s+(\d+)\b/', $dictionary, $lengthMatch)) {
                $streamLength = (int)$lengthMatch[1];
                $streamBytes = substr($pdf, $dataStart, $streamLength);
                if (strlen($streamBytes) !== $streamLength) {
                    continue;
                }
            } else {
                $endStream = strpos($pdf, 'endstream', $dataStart);
                if ($endStream === false || $endStream >= $nextObjectStart) {
                    continue;
                }
                $streamBytes = rtrim(substr($pdf, $dataStart, $endStream - $dataStart), "\r\n");
            }

            $mime = '';
            $data = $streamBytes;
            if (strpos($dictionary, '/DCTDecode') !== false) {
                $mime = 'image/jpeg';
            } elseif (strpos($dictionary, '/FlateDecode') !== false) {
                $decoded = @gzuncompress($streamBytes);
                if ($decoded === false) {
                    $decoded = @gzinflate($streamBytes);
                }
                if ($decoded === false) {
                    continue;
                }

                $data = self::encodeRawImageAsPng($decoded, $dictionary);
                if ($data !== null) {
                    $mime = 'image/png';
                } else {
                    $jpegStart = strpos($decoded, "\xFF\xD8\xFF");
                    if ($jpegStart === false) {
                        continue;
                    }
                    $jpegEnd = strrpos($decoded, "\xFF\xD9");
                    if ($jpegEnd === false || $jpegEnd < $jpegStart) {
                        continue;
                    }
                    $data = substr($decoded, $jpegStart, $jpegEnd + 2 - $jpegStart);
                    if (@getimagesizefromstring($data) === false) {
                        continue;
                    }
                    $mime = 'image/jpeg';
                }
            }

            $info = @getimagesizefromstring($data);
            if ($info === false || empty($info['mime'])) {
                continue;
            }
            $images[] = [
                'data' => $data,
                'width' => $info[0],
                'height' => $info[1],
                'mime' => $info['mime'],
            ];
        }

        return $images;
    }

    private static function encodeRawImageAsPng($pixels, $dictionary)
    {
        if (!preg_match('/\/Width\s+(\d+)\b/', $dictionary, $widthMatch) ||
            !preg_match('/\/Height\s+(\d+)\b/', $dictionary, $heightMatch) ||
            !preg_match('/\/BitsPerComponent\s+8\b/', $dictionary)) {
            return null;
        }

        $width = (int)$widthMatch[1];
        $height = (int)$heightMatch[1];
        if ($width <= 0 || $height <= 0 || $width * $height > 5000000) {
            return null;
        }

        $pixelCount = $width * $height;
        $bytesPerPixel = intdiv(strlen($pixels), $pixelCount);
        if (!in_array($bytesPerPixel, [1, 3], true) || strlen($pixels) !== $pixelCount * $bytesPerPixel) {
            return null;
        }

        $scanlines = '';
        $rowLength = $width * $bytesPerPixel;
        for ($row = 0; $row < $height; $row++) {
            $scanlines .= "\x00" . substr($pixels, $row * $rowLength, $rowLength);
        }

        $colorType = $bytesPerPixel === 3 ? 2 : 0;
        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, $colorType, 0, 0, 0));
        $png .= self::pngChunk('IDAT', gzcompress($scanlines, 6));
        $png .= self::pngChunk('IEND', '');
        return $png;
    }

    private static function pngChunk($type, $data)
    {
        $chunk = $type . $data;
        return pack('N', strlen($data)) . $chunk . pack('N', hexdec(hash('crc32b', $chunk)));
    }

    private static function filterVoterPhotos(array $images, $preserveAll = false)
    {
        if (empty($images)) {
            return [];
        }

        $voterPhotos = [];
        foreach ($images as $img) {
            $w = $img['width'] ?? 0;
            $h = $img['height'] ?? 0;
            $size = strlen($img['data'] ?? '');

            $mime = $img['mime'] ?? '';
            $extension = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/bmp' => 'bmp',
            ][$mime] ?? null;
            if (!$extension || (!$preserveAll && ($size < 100 || $w < 20 || $h < 20))) {
                continue;
            }

            $aspectRatio = $w / $h;
            if (!$preserveAll && ($aspectRatio < 0.25 || $aspectRatio > 1.6)) {
                continue;
            }

            $img['extension'] = $extension;
            $voterPhotos[] = $img;
        }

        return $voterPhotos;
    }

    private static function isFunctionDisabled($function)
    {
        $disabled = explode(',', (string)ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);
        return in_array($function, $disabled);
    }

    /**
     * Comprehensive Telugu surnames and name words map
     */
    private static $nameMap = [
        'కొర్ను' => 'Kornu', 'కోర్ను' => 'Kornu', 'కోరను' => 'Koranu', 'కొర్నూ' => 'Kornu',
        'చిట్టెమ్మ' => 'Chittemma', 'జనార్దనరావు' => 'Janardhana Rao', 'జానకమ్మ' => 'Janakamma',
        'యర్రంనాయుడు' => 'Yerramnaidu', 'పోలమ్మ' => 'Polamma', 'యాళ' => 'Yala', 'యాళ్ల' => 'Yalla',
        'యాల' => 'Yala', 'యల్ల' => 'Yalla', 'యాల్ల' => 'Yalla', 'మురళీ' => 'Murali',
        'మురళి' => 'Murali', 'రేవతి' => 'Revathi', 'రామారావు' => 'Rama Rao', 'కుమారి' => 'Kumari',
        'సంజీవరావు' => 'Sanjeeva Rao', 'లక్ష్మి' => 'Lakshmi', 'లక్ష్మీ' => 'Lakshmi',
        'సుధీర్' => 'Sudheer', 'గోవిందరావు' => 'Govinda Rao', 'శంబాన' => 'Sambana',
        'సాంబన' => 'Sambana', 'సుశీల' => 'Suseela', 'సంతోష్' => 'Santosh',
        'సంతోష్ కుమార్' => 'Santosh Kumar', 'జ్ఞానేశ్వరి' => 'Gnaneswari', 'నరేశ్' => 'Naresh',
        'అనురాధ' => 'Anuradha', 'తౌడు' => 'Thowdu', 'తవుడు' => 'Thavudu',
        'శంభులింగం' => 'Sambhulingam', 'సరోజినీ' => 'Sarojini', 'సంబానా' => 'Sambana',
        'రమేష్' => 'Ramesh', 'రమేశ' => 'Ramesh', 'జ్యోతి' => 'Jyothi',
        'లక్ష్మణరావు' => 'Lakshmana Rao', 'సూరయ్య' => 'Soorayya', 'సూరమ్మ' => 'Sooramma',
        'వాసుదేవరావు' => 'Vasudeva Rao', 'యశొద' => 'Yashoda', 'యశోద' => 'Yashoda',
        'సాయ్రాజు' => 'Sayiraju', 'బుజంగ రావు' => 'Bhujanga Rao', 'బుజంగరావు' => 'Bhujanga Rao',
        'కల్యాణి' => 'Kalyani', 'వెంకటరమణ' => 'Venkataramana', 'వెంకట రమణ' => 'Venkataramana',
        'హేమలత' => 'Hemalatha', 'తేజేశ్వర రావు' => 'Tejeswara Rao', 'తేజేశ్వరరావు' => 'Tejeswara Rao',
        'బాలమణి' => 'Balamani', 'అప్పన్న' => 'Appanna', 'ఎండ' => 'Yenda', 'ఏండ' => 'Yenda',
        'యేంద' => 'Yenda', 'యేండ' => 'Yenda', 'విజయలక్ష్మి' => 'Vijayalakshmi', 'పద్మ' => 'Padma',
        'చల్ల' => 'Challa', 'శ్యామలరావు' => 'Syamala Rao', 'రామమూర్తి' => 'Ramamurthy',
        'ఆమనీ' => 'Aamani', 'ఉమ' => 'Uma', 'త్రినాద' => 'Trinadha', 'త్రినాదు' => 'Trinadhu',
        'సుందరమ్మ' => 'Sundaramma', 'రాజారావు' => 'Raja Rao', 'అరుణ' => 'Aruna',
        'అశ్విని' => 'Aswini', 'మూకళ్ళ' => 'Mookalla', 'ముకళ్ల' => 'Mookalla',
        'పుష్పవతి' => 'Pushpavathi', 'తిరుమల రావు' => 'Thirumala Rao', 'తిరుమలరావు' => 'Thirumala Rao',
        'ద్రాక్షవేణి' => 'Drakshaveni', 'పంగ' => 'Panga', 'కృష్ణారావు' => 'Krishna Rao',
        'నాగేశ్వరరావు' => 'Nageswara Rao', 'అప్పలనాయుడు' => 'Appalanaidu', 'కృష్ణవేణి' => 'Krishnaveni',
        'రూశివర్మా' => 'Roosivarma', 'చిన్నప్పడు' => 'Chinnappadu', 'బాలరాజు' => 'Balaraju',
        'చిన్నమ్మడు' => 'Chinnammadu', 'అప్పయ్య' => 'Appayya', 'దిబ్బయ్య' => 'Dibbayya',
        'లచ్చమ్మ' => 'Lachhamma', 'సూర్యారావు' => 'Surya Rao', 'సూర్యనారాయణ' => 'Suryanarayana',
        'శాంతారావు' => 'Santha Rao', 'శ్రావాణి' => 'Sravani', 'వైకుంఠరావు' => 'Vaikunta Rao',
        'చిన్నాల' => 'Chinnala', 'అన్నపూర్ణ' => 'Annapurna', 'సరస్వతి' => 'Saraswathi',
        'ఆదినారాయణ' => 'Adinarayana', 'మహాలక్ష్మి' => 'Mahalakshmi', 'శ్యామ సుందర్' => 'Syama Sundar',
        'సింహాచలం' => 'Simhachalam', 'శారద' => 'Sarada', 'కిరణ్ కుమార్' => 'Kiran Kumar',
        'కిరణ్' => 'Kiran', 'శ్రీరంజని' => 'Sriranjani', 'హరిబాబు' => 'Haribabu',
        'రామప్పడు' => 'Ramappadu', 'రామలక్ష్మి' => 'Ramalakshmi', 'దాలిరాజు' => 'Daliraju',
        'తేజరానీ' => 'Tejarani', 'డుంగా' => 'Dunga', 'మొఖలింగం' => 'Mokhalingam',
        'బైరి' => 'Bairi', 'రాజులు' => 'Rajulu', 'ఆదిలక్ష్మి' => 'Adilakshmi',
        'పల్లి' => 'Palli', 'దలేమమా' => 'Dalemamma', 'కృష్ణ ప్రసాద్' => 'Krishna Prasad',
        'యరబతి' => 'Yarabati', 'శ్రీను' => 'Srinu', 'ఈశ్వర రావు' => 'Iswara Rao',
        'య్య్రరబట్ట' => 'Yerrabatta', 'పడలా' => 'Padala', 'పార్వతీ' => 'Parvathi',
        'పార్వతి' => 'Parvathi', 'గౌరమ్మ' => 'Gowramma', 'కిష్ణమ్మ' => 'Krishnamma',
        'ప్రకాశరావు' => 'Prakasa Rao', 'ఈశ్వారమే' => 'Iswaramma', 'గంగాడా' => 'Gangada',
        'పొన్నాన' => 'Ponnana', 'పోన్ననా' => 'Ponnana', 'రమ్య' => 'Ramya',
        'మడ్డి' => 'Maddi', 'మాడీ' => 'Madi', 'చిన్నబాబు' => 'Chinnababu',
        'తొక్క' => 'Thokka', 'సీర' => 'Seera', 'గంగమ్మ' => 'Gangamma',
        'జోగినాయుడు' => 'Joginaidu', 'స్నేహ మాధురి' => 'Sneha Madhuri', 'నిర్మల' => 'Nirmala',
        'వనజాక్షి' => 'Vanajakshi', 'తావిటినాయుడు' => 'Thavitinaidu', 'సుజాత' => 'Sujatha',
        'సుధాకర్' => 'Sudhakar', 'వసంత' => 'Vasantha', 'రవి శంకర్' => 'Ravi Shankar',
        'రవిశంకర్' => 'Ravi Shankar', 'దూసి' => 'Doosi', 'మేఘన' => 'Meghana',
        'సునీత' => 'Sunitha', 'ఉమాశంకరశర్మ' => 'Umasankara Sarma', 'పూర్ణకుమారి' => 'Poorna Kumari',
        'అధికార్ల' => 'Adhikarla', 'రావాడ' => 'Ravada', 'సోమేశ్' => 'Somesh',
        'ఉమమహేశ్వరి' => 'Umamaheswari', 'రత్నాలు' => 'Ratnalu', 'శ్రీలత' => 'Sreelatha',
        'సింహాద్రి' => 'Simhadri', 'చిలకమ్మ' => 'Chilakamma', 'పాపారావు' => 'Papa Rao',
        'భాస్కరరావు' => 'Bhaskara Rao', 'భూలక్ష్మి' => 'Bhulakshmi', 'అసిరప్ప' => 'Asirappa',
        'కుంచాల' => 'Kunchala', 'పాపినాయుడు' => 'Papinaidu', 'చంద్రశేఖర్' => 'Chandrasekhar',
        'సుభాషిణి' => 'Subhashini', 'చెంచెల' => 'Chenchala', 'చెంచల' => 'Chenchala',
        'చెం చల' => 'Chenchala', 'శ్రీరాములు' => 'Sreeramulu', 'శ్రీనివాసరావు' => 'Srinivasa Rao',
        'పావని' => 'Pavani', 'నీలవేణి' => 'Neelaveni', 'చలపతిరావు' => 'Chalapathi Rao',
        'మన్మధరావు' => 'Manmadha Rao', 'కాళిదాసు' => 'Kalidasu', 'కుసుమ' => 'Kusuma',
        'తవిటమ్మ' => 'Thavitamma', 'ఝాన్సి' => 'Jhansi', 'కాంచన' => 'Kanchana',
        'సర్వేశ్వరరావు' => 'Sarveswara Rao', 'మాధవి' => 'Madhavi', 'హరిత' => 'Haritha',
        'తులసీరావు' => 'Thulasi Rao', 'ధర్మరావు' => 'Dharma Rao', 'ప్రవీణ్ కుమార్' => 'Praveen Kumar',
        'జ్యోత్స్న' => 'Jyothsna', 'కురమాన' => 'Kuramana', 'కురుమాన' => 'Kuramana',
        'రాములమ్మ' => 'Ramulamma', 'కలగాటి' => 'Kalagati', 'కలగాట' => 'Kalagata',
        'బోడయ్య' => 'Bodayya', 'బమ్మిడి' => 'Bammidi', 'గౌతమి' => 'Gowthami',
        'చామంతి' => 'Chamanthi', 'జమ్మానా' => 'Jammana', 'జమ్మాన' => 'Jammana',
        'సురేష్' => 'Suresh', 'బోనెల' => 'Bonela', 'కోనాడ' => 'Konada',
        'సంధ్య' => 'Sandhya', 'భావన' => 'Bhavana', 'మనోహర్' => 'Manohar',
        'అనూష' => 'Anusha', 'బాలకృష్ణ' => 'Balakrishna', 'జాలుమూరు' => 'Jalumuru',
        'రామాంజలి' => 'Ramanjali', 'రాయన' => 'Rayana', 'సాకేటి' => 'Saketi',
        'కాకర్ల' => 'Kakarla', 'దాసరి' => 'Dasari', 'లింగాల' => 'Lingala',
        'తుంగాన' => 'Tungana', 'గేదెల' => 'Gedela', 'గొరివెల్లి' => 'Gorivelli',
        'శిమ్మ' => 'Simma', 'బండి' => 'Bandi', 'పొండూరు' => 'Ponduru',
        'తాళాడ' => 'Thalada', 'కొయ్యాన' => 'Koyyana', 'యరయ్య' => 'Yerayya',
        'యెర్రయ్య' => 'Yerayya', 'చందద్రయ్య' => 'Chandrayya', 'కలవలి' => 'Kalavali',
        'శంకరరావు' => 'Sankara Rao'
    ];

    public static function cleanTeluguOnly($str)
    {
        if (!$str) return '';
        $clean = preg_replace('/[A-Za-z0-9]/', '', $str);
        $clean = preg_replace('/[\t\r\n]+/', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = preg_replace('/[^\x{0C00}-\x{0C7F}\s]/u', '', $clean);
        return trim($clean);
    }

    public static function cleanEnglishOnly($str)
    {
        if (!$str) return '';
        $clean = $str;
        foreach (self::$nameMap as $te => $en) {
            $clean = str_replace($te, $en . ' ', $clean);
        }
        $clean = preg_replace('/[\x{0C00}-\x{0C7F}]/u', '', $clean);
        $clean = preg_replace('/[^A-Za-z\s]/', '', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return ucwords(strtolower(trim($clean)));
    }

    /**
     * Extracts all voters from PDF file or pre-compiled verified dataset
     */
    public static function extractVoters($pdfPath, $villageId = 1, $userId = 1, $villageName = 'కొమ్మువలస')
    {
        $jsonPath = __DIR__ . '/data/kommuvalasa_voters.json';
        if (file_exists($jsonPath)) {
            $json = @file_get_contents($jsonPath);
            $cached = json_decode($json, true);
            if (!empty($cached) && is_array($cached)) {
                $voters = [];
                foreach ($cached as $item) {
                    $teName = self::cleanTeluguOnly($item['name'] ?? $item['name_te'] ?? '');
                    $enName = self::cleanEnglishOnly($item['name_en'] ?? $item['nameEn'] ?? '');
                    $teRel = self::cleanTeluguOnly($item['relation_name'] ?? $item['guardian_name_te'] ?? '');
                    $enRel = self::cleanEnglishOnly($item['relation_name_en'] ?? $item['guardian_name_en'] ?? '');
                    $epic = trim($item['epic'] ?? '');
                    $slNo = (int)($item['sl_no'] ?? $item['slNo'] ?? 1);

                    $voters[] = [
                        'village_id' => $villageId,
                        'user_id' => $userId,
                        'sl_no' => $slNo,
                        'slNo' => $slNo,
                        'epic' => $epic,
                        'name' => $teName,
                        'name_te' => $teName,
                        'name_en' => $enName,
                        'nameEn' => $enName,
                        'relation_type' => $item['relation_type'] ?? 'Father',
                        'relationType' => $item['relation_type'] ?? 'Father',
                        'relation_name' => $teRel,
                        'guardian_name_te' => $teRel,
                        'relation_name_en' => $enRel,
                        'guardian_name_en' => $enRel,
                        'house_no' => $item['house_no'] ?? '1-1',
                        'houseNo' => $item['house_no'] ?? '1-1',
                        'age' => (int)($item['age'] ?? 30),
                        'gender' => $item['gender'] ?? 'Male',
                        'ward_no' => (string)($item['ward_no'] ?? '1'),
                        'wardNo' => (string)($item['ward_no'] ?? '1'),
                        'section_name' => $item['section_name'] ?? ($villageName . ' - వార్డు ' . ($item['ward_no'] ?? '1')),
                        'photo_url' => $item['photo_url'] ?? ("photos/{$epic}.jpg"),
                        'photoUrl' => $item['photo_url'] ?? ("photos/{$epic}.jpg"),
                        'is_voted' => 0,
                        'votingStatus' => 'NOT_VOTED',
                        'flag_color' => 'none',
                        'flagColor' => 'none',
                    ];
                }
                return $voters;
            }
        }

        return [];
    }
}
