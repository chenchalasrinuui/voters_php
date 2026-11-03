<?php
// backend/pdf_extractor.php - Pure PHP Electoral Roll PDF Voter Extraction, Transliteration & Photo Extraction

class PdfExtractor
{
    /**
     * Extracts photos from the uploaded PDF and places them inside the target village folder:
     * uploads/<state>/<district>/<constituency>/<mandal>/<village>/<voter_id_no>.jpg
     *
     * @param string $pdfPath     Full filesystem path to the PDF file
     * @param array  $voters      Array of voter records
     * @param string $targetDir   Full filesystem path to uploads/<state>/<district>/<constituency>/<mandal>/<village>
     * @param string $relativeDir Relative path starting with uploads/...
     * @return array Array of relative photo URLs indexed by voter index and EPIC
     */
    public static function extractPhotos($pdfPath, array $voters, $targetDir, $relativeDir)
    {
        if (empty($voters)) {
            return [];
        }

        // Ensure target folder exists and is writable
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                throw new RuntimeException("Unable to create village photo upload directory: {$targetDir}");
            }
        }
        @chmod($targetDir, 0777);

        $savedPhotos = [];
        $extractedImages = [];

        // 1. Attempt extraction from PDF file if readable
        if (!empty($pdfPath) && is_file($pdfPath) && is_readable($pdfPath)) {
            $extractedImages = self::extractImagesFromPdf($pdfPath);
        }

        // 2. Identify and filter voter photos from extracted images
        $voterPhotos = self::filterVoterPhotos($extractedImages);

        // 3. Associate each voter with extracted photo or fallback photo
        $totalVoters = count($voters);
        $projectRoot = dirname(__DIR__);

        for ($i = 0; $i < $totalVoters; $i++) {
            $voter = $voters[$i];
            
            // Determine voter ID number (EPIC or serial number / ID)
            $voterIdNo = trim((string)(
                $voter['epic'] ?? 
                $voter['voter_id'] ?? 
                $voter['voterId'] ?? 
                $voter['sl_no'] ?? 
                $voter['slNo'] ?? 
                $voter['id'] ?? 
                ($i + 1)
            ));
            
            // Sanitize voter ID for filename
            $safeVoterId = preg_replace('/[^A-Za-z0-9_\-]/', '', $voterIdNo);
            if ($safeVoterId === '') {
                $safeVoterId = 'voter_' . ($i + 1);
            }

            $fileName = $safeVoterId . '.jpg';
            $destFile = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
            $relativePhotoUrl = rtrim($relativeDir, '/') . '/' . $fileName;

            // Strategy A: If an image was extracted for this voter
            if (isset($voterPhotos[$i]) && !empty($voterPhotos[$i]['data'])) {
                $written = @file_put_contents($destFile, $voterPhotos[$i]['data'], LOCK_EX);
                if ($written !== false && $written > 0) {
                    $savedPhotos[$i] = $relativePhotoUrl;
                    $savedPhotos[$safeVoterId] = $relativePhotoUrl;
                    continue;
                }
            }

            // Strategy B: If photo already exists at destination, keep it
            if (file_exists($destFile) && filesize($destFile) > 100) {
                $savedPhotos[$i] = $relativePhotoUrl;
                $savedPhotos[$safeVoterId] = $relativePhotoUrl;
                continue;
            }

            // Strategy C: Check existing photo pools in project (photos/ or uploads/)
            $candidateExistingPhotos = [
                $projectRoot . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $safeVoterId . '.jpg',
                $projectRoot . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $voterIdNo . '.jpg',
                $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $safeVoterId . '.jpg',
            ];

            $foundExisting = false;
            foreach ($candidateExistingPhotos as $srcPhoto) {
                if (file_exists($srcPhoto) && is_readable($srcPhoto) && filesize($srcPhoto) > 50) {
                    if (@copy($srcPhoto, $destFile)) {
                        $savedPhotos[$i] = $relativePhotoUrl;
                        $savedPhotos[$safeVoterId] = $relativePhotoUrl;
                        $foundExisting = true;
                        break;
                    }
                }
            }

            if ($foundExisting) {
                continue;
            }

            // Strategy D: If voter has a valid photo_url in dataset, preserve or copy
            if (!empty($voter['photo_url'])) {
                $srcUrl = $voter['photo_url'];
                $srcPath = $projectRoot . DIRECTORY_SEPARATOR . ltrim($srcUrl, '/\\');
                if (file_exists($srcPath) && is_file($srcPath) && filesize($srcPath) > 50) {
                    @copy($srcPath, $destFile);
                }
                $savedPhotos[$i] = $relativePhotoUrl;
                $savedPhotos[$safeVoterId] = $relativePhotoUrl;
                continue;
            }

            // Default relative photo URL
            $savedPhotos[$i] = $relativePhotoUrl;
            $savedPhotos[$safeVoterId] = $relativePhotoUrl;
        }

        return $savedPhotos;
    }

    /**
     * Extracts raw image byte streams from a PDF file using multiple complementary methods.
     *
     * @param string $pdfPath Full filesystem path to the PDF file
     * @return array Array of image data descriptors: [['type' => 'jpg', 'data' => $binary, 'width' => ..., 'height' => ...], ...]
     */
    public static function extractImagesFromPdf($pdfPath)
    {
        $images = [];

        // Method 1: CLI pdfimages tool if available on server
        if (function_exists('exec') && !self::isFunctionDisabled('exec')) {
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_imgs_' . uniqid();
            if (@mkdir($tmpDir, 0777, true)) {
                $cmd = sprintf('pdfimages -png %s %s/img 2>&1', escapeshellarg($pdfPath), escapeshellarg($tmpDir));
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
                                $images[] = [
                                    'type' => $ext === 'png' ? 'png' : 'jpg',
                                    'data' => $data,
                                    'width' => $info[0] ?? 0,
                                    'height' => $info[1] ?? 0,
                                ];
                            }
                        }
                    }
                }
                // Cleanup temp dir
                @array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);

                if (!empty($images)) {
                    return $images;
                }
            }
        }

        // Method 2: Pure PHP Stream & DCTDecode Object Parser
        $pdf = @file_get_contents($pdfPath);
        if ($pdf === false || strlen($pdf) < 32) {
            return [];
        }

        // 2A: Parse PDF Objects with /Subtype /Image
        preg_match_all(
            '/(?:<<[^\>]*\/Subtype\s*\/Image[^\>]*>>)\s*stream[\r\n]+(.*?)[\r\n]+endstream/si',
            $pdf,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $header = $match[0];
            $streamData = $match[1];

            // If DCTDecode (JPEG)
            if (stripos($header, 'DCTDecode') !== false) {
                // Ensure starts with JPEG SOI marker \xFF\xD8
                $soi = strpos($streamData, "\xFF\xD8\xFF");
                if ($soi !== false) {
                    $jpegBytes = substr($streamData, $soi);
                    $eoi = strrpos($jpegBytes, "\xFF\xD9");
                    if ($eoi !== false) {
                        $jpegBytes = substr($jpegBytes, 0, $eoi + 2);
                    }
                    if (strlen($jpegBytes) > 200) {
                        $info = @getimagesizefromstring($jpegBytes);
                        $images[] = [
                            'type' => 'jpg',
                            'data' => $jpegBytes,
                            'width' => $info[0] ?? 0,
                            'height' => $info[1] ?? 0,
                        ];
                    }
                }
            } elseif (stripos($header, 'FlateDecode') !== false) {
                // Decompress zlib stream
                $decompressed = @gzuncompress($streamData);
                if ($decompressed === false) {
                    $decompressed = @gzinflate(substr($streamData, 2));
                }
                if ($decompressed !== false && strlen($decompressed) > 200) {
                    // Check if decompressed stream contains embedded JPEG
                    $soi = strpos($decompressed, "\xFF\xD8\xFF");
                    if ($soi !== false) {
                        $jpegBytes = substr($decompressed, $soi);
                        $eoi = strrpos($jpegBytes, "\xFF\xD9");
                        if ($eoi !== false) {
                            $jpegBytes = substr($jpegBytes, 0, $eoi + 2);
                        }
                        if (strlen($jpegBytes) > 200) {
                            $info = @getimagesizefromstring($jpegBytes);
                            $images[] = [
                                'type' => 'jpg',
                                'data' => $jpegBytes,
                                'width' => $info[0] ?? 0,
                                'height' => $info[1] ?? 0,
                            ];
                        }
                    }
                }
            }
        }

        // Method 3: Direct binary scan for valid JPEG byte streams
        if (count($images) < 5) {
            $offset = 0;
            $pdfLen = strlen($pdf);

            while ($offset < $pdfLen - 4) {
                $start = strpos($pdf, "\xFF\xD8\xFF", $offset);
                if ($start === false) {
                    break;
                }

                // Parse exact JPEG structure to locate authentic End of Image (EOI 0xFF 0xD9)
                $jpeg = self::parseJpegBoundary($pdf, $start, $pdfLen);
                if ($jpeg !== null && strlen($jpeg['data']) > 200) {
                    $info = @getimagesizefromstring($jpeg['data']);
                    $images[] = [
                        'type' => 'jpg',
                        'data' => $jpeg['data'],
                        'width' => $info[0] ?? 0,
                        'height' => $info[1] ?? 0,
                    ];
                    $offset = $jpeg['end'];
                } else {
                    $offset = $start + 3;
                }
            }
        }

        return $images;
    }

    /**
     * Parses the boundary of a JPEG image starting at $start offset.
     * Accurately distinguishes marker bytes from payload data and byte-stuffed 0xFF 0x00.
     */
    private static function parseJpegBoundary(&$data, $start, $dataLen)
    {
        $pos = $start + 2;
        $end = false;

        while ($pos < $dataLen - 1) {
            // Find next 0xFF
            $pos = strpos($data, "\xFF", $pos);
            if ($pos === false || $pos >= $dataLen - 1) {
                break;
            }

            // Skip successive 0xFF padding bytes
            while ($pos < $dataLen && $data[$pos] === "\xFF") {
                $pos++;
            }
            if ($pos >= $dataLen) {
                break;
            }

            $marker = ord($data[$pos]);
            $pos++;

            // 0x00 is escaped 0xFF byte in entropy scan data
            if ($marker === 0x00) {
                continue;
            }

            // Restart markers (RST0 - RST7: 0xD0 - 0xD7) have no payload length
            if ($marker >= 0xD0 && $marker <= 0xD7) {
                continue;
            }

            // EOI (End of Image)
            if ($marker === 0xD9) {
                $end = $pos;
                break;
            }

            // SOS (Start of Scan) - scan data begins after SOS segment length
            if ($marker === 0xDA) {
                if ($pos + 2 <= $dataLen) {
                    $sosLen = (ord($data[$pos]) << 8) | ord($data[$pos + 1]);
                    $pos += $sosLen;
                }
                continue;
            }

            // Any other marker is followed by 2-byte segment length
            if ($pos + 2 <= $dataLen) {
                $segLen = (ord($data[$pos]) << 8) | ord($data[$pos + 1]);
                if ($segLen < 2) {
                    break;
                }
                $pos += $segLen;
            } else {
                break;
            }
        }

        if ($end !== false) {
            $jpegData = substr($data, $start, $end - $start);
            return [
                'start' => $start,
                'end' => $end,
                'data' => $jpegData
            ];
        }

        return null;
    }

    /**
     * Filters out logos, polling booth maps, or document headers to isolate voter passport portraits.
     */
    private static function filterVoterPhotos(array $images)
    {
        if (empty($images)) {
            return [];
        }

        $voterPhotos = [];
        foreach ($images as $img) {
            $w = $img['width'] ?? 0;
            $h = $img['height'] ?? 0;
            $size = strlen($img['data'] ?? '');

            // Skip tiny icons (< 150 bytes or < 30px)
            if ($size < 150) {
                continue;
            }

            // If dimensions are available, filter out landscape maps (width > 2.5 * height)
            if ($w > 0 && $h > 0) {
                if ($w > 400 && $h > 400 && ($w / $h > 1.8)) {
                    // Polling station wide map
                    continue;
                }
            }

            $voterPhotos[] = $img;
        }

        // If filtering removed all, fall back to unfiltered images
        if (empty($voterPhotos)) {
            return $images;
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

    /**
     * Strictly removes any English/Latin characters to keep pure Telugu
     */
    public static function cleanTeluguOnly($str)
    {
        if (!$str) return '';
        $clean = preg_replace('/[A-Za-z0-9]/', '', $str);
        $clean = preg_replace('/[\t\r\n]+/', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = preg_replace('/[^\x{0C00}-\x{0C7F}\s]/u', '', $clean);
        return trim($clean);
    }

    /**
     * Strictly removes any Telugu characters to keep pure English
     */
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
     * Extracts all voters from PDF file and links them to village_id and user_id
     */
    public static function extractVoters($pdfPath, $villageId, $userId = 1, $villageName = 'కొమ్మువలస')
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
