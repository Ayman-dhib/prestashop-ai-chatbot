<?php

use GuzzleHttp\Client;

// Adjust autoload to point to PrestaShop root vendor/
require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Build embeddings JSON next to the KB file:
 * /path/to/faq.txt.embeddings.json
 */
function build_faq_embeddings(string $faqPath, string $apiKey, string $embedModel = 'text-embedding-3-small'): string
{
    if (!is_file($faqPath))      throw new Exception("FAQ file not found: $faqPath");
    if (empty($apiKey))          throw new Exception("Missing OpenAI API key.");

    $text = file_get_contents($faqPath);

    // PDF support (requires poppler-utils: pdftotext)
    if (preg_match('/\.pdf$/i', $faqPath)) {
        $tmp = sys_get_temp_dir().'/kb_'.uniqid().'.txt';
        @shell_exec('pdftotext -layout '.escapeshellarg($faqPath).' '.escapeshellarg($tmp));
        if (!is_file($tmp)) throw new Exception("pdftotext not available in container.");
        $text = file_get_contents($tmp); @unlink($tmp);
    }

    // Strip UTF-8 BOM so the very first "Q:" is recognized
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

    // ---- Parse Q:/A: with multi-line answers until the next Q: ----
    $pairs = [];
    $lines = preg_split('/\R/u', $text);
    $curQ = null;
    $curA = [];
    $flush = function() use (&$pairs, &$curQ, &$curA) {
        if ($curQ !== null && $curA) {
            $pairs[] = ['q' => trim($curQ), 'a' => trim(implode("\n", $curA))];
        }
        $curQ = null; $curA = [];
    };

    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;

        // New question starts
        if (preg_match('/^Q\s*[:\-]\s*(.+)$/iu', $line, $m)) {
            $flush();
            $curQ = $m[1];
            continue;
        }
        // First line of the answer
        if ($curQ !== null && preg_match('/^A\s*[:\-]\s*(.+)$/iu', $line, $m)) {
            $curA[] = $m[1];
            continue;
        }
        // Continuation lines for the current answer (until next Q:)
        if ($curQ !== null && $curA) {
            $curA[] = $line;
        }
    }
    $flush();

    if (!$pairs) throw new Exception("No Q:/A: pairs found.");

    // ---- Batch embeddings for the questions ----
    $http = new Client([
        'base_uri' => 'https://api.openai.com/v1/',
        'timeout'  => 40,
        'headers'  => ['Authorization' => 'Bearer '.$apiKey, 'Content-Type' => 'application/json'],
    ]);

    $batch = 32;
    $out   = [];
    for ($i=0; $i<count($pairs); $i += $batch) {
        $slice  = array_slice($pairs, $i, $batch);
        $inputs = array_map(fn($p) => $p['q'], $slice);

        $resp = $http->post('embeddings', ['json' => ['model' => $embedModel, 'input' => $inputs]]);
        $data = json_decode((string)$resp->getBody(), true);

        foreach ($slice as $k => $row) {
            $out[] = [
                'q'         => $row['q'],
                'a'         => $row['a'],
                'embedding' => $data['data'][$k]['embedding'] ?? [],
                'qt'        => tokens($row['q']),
                'at'        => tokens($row['a']),
            ];
        }
    }

    // ---- Build simple IDF for BM25-like keyword scoring ----
    $df=[]; $docs=[];
    foreach ($out as $r) {
        $doc = array_unique(array_merge($r['qt'], $r['at']));
        $docs[] = $doc;
        foreach ($doc as $t) { $df[$t] = ($df[$t] ?? 0) + 1; }
    }
    $N = max(1, count($docs));
    $idf=[];
    foreach ($df as $t => $d) { $idf[$t] = log(($N - $d + 0.5) / ($d + 0.5) + 1.0); }

    $json = ['pairs' => $out, 'idf' => $idf, 'meta' => ['model' => $embedModel, 'count' => count($out)]];
    $outPath = $faqPath . '.embeddings.json';
    file_put_contents($outPath, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $outPath;
}

function tokens(string $text): array {
    $text = mb_strtolower($text,'UTF-8');
    $noAcc = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text); if($noAcc!==false)$text=$noAcc;
    $text = preg_replace('/[^0-9a-z\s]/i',' ',$text);
    $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if(!$parts) return [];
    static $stop=['the','a','an','and','or','of','for','to','is','are','on','in',
                  'la','le','les','de','des','du','et','ou','au','aux','un','une'];
    return array_values(array_unique(array_diff($parts,$stop)));
}

/* ---- CLI runner ---- */
if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $faqPath = $argv[1] ?? '';
    $apiKey  = getenv('OPENAI_API_KEY') ?: ($argv[2] ?? '');

    if (!$faqPath || !$apiKey) {
        fwrite(STDERR, "Usage: php faq_embed_admin.php <path/to/faq.txt|.pdf> [OPENAI_API_KEY]\n");
        fwrite(STDERR, "Or set env var OPENAI_API_KEY in the container.\n");
        exit(2);
    }
    try {
        $out = build_faq_embeddings($faqPath, $apiKey, 'text-embedding-3-small');
        echo "OK: wrote $out\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
        exit(1);
    }
}
