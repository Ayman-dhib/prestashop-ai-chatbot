<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../init.php');
require_once(dirname(__FILE__).'/controllers/front/api.php');

class TestKBTranslation extends Ai_ChatbotApiModuleFrontController {
    public function __construct() {
        // Initialisation minimale pour les tests
        $this->debug = true;
    }
    
    // Méthode pour tester la traduction et la recherche dans la KB
    public function testKBTranslation() {
        echo "=== TEST DE TRADUCTION ET RECHERCHE KB EN FRANÇAIS ===\n\n";
        
        // 1. Test de détection de langue
        $testQueries = [
            'Comment retourner un produit?' => 'fr',
            'Quelle est votre politique de retour?' => 'fr',
            'How do I return a product?' => 'en',
            'What is your return policy?' => 'en'
        ];
        
        echo "1. TEST DE DÉTECTION DE LANGUE:\n";
        foreach ($testQueries as $query => $expectedLang) {
            $detectedLang = $this->detectLanguageAdvanced($query);
            echo "   Query: '$query'\n";
            echo "   Langue détectée: $detectedLang (attendu: $expectedLang)\n";
            echo "   " . ($detectedLang === $expectedLang ? "✓ OK" : "✗ ERREUR") . "\n\n";
        }
        
        // 2. Test de traduction pour traitement
        echo "\n2. TEST DE TRADUCTION POUR TRAITEMENT:\n";
        foreach ($testQueries as $query => $lang) {
            $translated = $this->translateForProcessing($query, $lang);
            echo "   Query: '$query' (langue: $lang)\n";
            echo "   Traduit pour traitement: '$translated'\n\n";
        }
        
        // 3. Test de tokenisation
        echo "\n3. TEST DE TOKENISATION:\n";
        foreach ($testQueries as $query => $lang) {
            $tokens = $this->tok($query);
            echo "   Query: '$query'\n";
            echo "   Tokens: " . json_encode($tokens) . "\n\n";
        }
        
        // 4. Test de recherche dans la KB
        echo "\n4. TEST DE RECHERCHE DANS LA KB:\n";
        
        // Obtenir le chemin de la KB
        $kbPath = $this->getKnowledgeBasePath();
        $apiKey = Configuration::get('AI_CHATBOT_API_KEY');
        
        if (!$kbPath) {
            echo "   ERREUR: Impossible de trouver le chemin de la KB\n";
        } else {
            echo "   Chemin de la KB: $kbPath\n";
            echo "   Fichier d'embeddings: $kbPath.embeddings.json\n\n";
            
            // Test avec des requêtes en français et en anglais
            $testSearchQueries = [
                'fr' => [
                    'Comment retourner un produit?',
                    'Quelle est votre politique de retour?',
                    'Comment contacter le service client?',
                    'Quels sont les délais de livraison?'
                ],
                'en' => [
                    'How do I return a product?',
                    'What is your return policy?',
                    'How to contact customer service?',
                    'What are the delivery times?'
                ]
            ];
            
            foreach ($testSearchQueries as $lang => $queries) {
                echo "   === Tests en $lang ===\n";
                foreach ($queries as $query) {
                    echo "   Query: '$query'\n";
                    
                    // Test avec traduction
                    $answer = $this->searchFAQ($query, $lang, $apiKey, $kbPath, $kbPath . '.embeddings.json');
                    echo "   Réponse: " . ($answer ? "'$answer'" : "Aucune réponse trouvée") . "\n\n";
                    
                    // Analyser le processus de recherche
                    $searchQuery = $lang === 'fr' ? $this->translateForProcessing($query, $lang) : $query;
                    echo "   Query traduite pour recherche: '$searchQuery'\n";
                    
                    // Charger la KB et tester les scores
                    $kb = $this->loadKB($kbPath . '.embeddings.json');
                    if ($kb) {
                        $keywordResult = $this->bm25Best($searchQuery, $kb);
                        echo "   Score BM25: " . ($keywordResult ? $keywordResult['score'] : "N/A") . "\n";
                        
                        if ($apiKey) {
                            $semanticResult = $this->semanticBest($searchQuery, $apiKey, $kb);
                            echo "   Score sémantique: " . ($semanticResult ? $semanticResult['score'] : "N/A") . "\n";
                        }
                    }
                    
                    echo "\n";
                }
            }
        }
    }
    
    // Méthode pour obtenir le chemin de la KB
    private function getKnowledgeBasePath() {
        $kbFile = Configuration::get('AI_CHATBOT_KB_FILE');
        if ($kbFile) {
            return _PS_MODULE_DIR_ . 'ai_chatbot/kb/' . $kbFile;
        }
        
        // Chercher le premier fichier KB disponible
        $kbDir = _PS_MODULE_DIR_ . 'ai_chatbot/kb/';
        if (is_dir($kbDir)) {
            $files = scandir($kbDir);
            foreach ($files as $file) {
                if (preg_match('/\.txt$/', $file)) {
                    return $kbDir . substr($file, 0, -4); // Retourne le chemin sans l'extension
                }
            }
        }
        
        return null;
    }
    
    // Méthode pour afficher le contenu de la KB
    public function displayKBContent() {
        $kbPath = $this->getKnowledgeBasePath();
        if (!$kbPath) {
            echo "Aucun fichier KB trouvé\n";
            return;
        }
        
        $txtFile = $kbPath . '.txt';
        $embFile = $kbPath . '.embeddings.json';
        
        echo "\n5. CONTENU DE LA KB:\n";
        if (file_exists($txtFile)) {
            echo "   Fichier texte: $txtFile\n";
            $content = file_get_contents($txtFile);
            echo "   Taille: " . strlen($content) . " octets\n";
            echo "   Premières lignes:\n";
            $lines = explode("\n", $content);
            for ($i = 0; $i < min(10, count($lines)); $i++) {
                echo "   $lines[$i]\n";
            }
            echo "   ...\n\n";
        }
        
        if (file_exists($embFile)) {
            echo "   Fichier embeddings: $embFile\n";
            $content = file_get_contents($embFile);
            $data = json_decode($content, true);
            echo "   Taille: " . strlen($content) . " octets\n";
            if ($data && isset($data['pairs'])) {
                echo "   Nombre de paires Q/R: " . count($data['pairs']) . "\n";
                echo "   Exemple de paires:\n";
                for ($i = 0; $i < min(3, count($data['pairs'])); $i++) {
                    $pair = $data['pairs'][$i];
                    echo "   Q: " . ($pair['q'] ?? 'N/A') . "\n";
                    echo "   A: " . substr($pair['a'] ?? 'N/A', 0, 100) . "...\n";
                    echo "   Tokens Q: " . json_encode($pair['qt'] ?? []) . "\n";
                    echo "   ---\n";
                }
            }
        }
    }
}

// Exécuter les tests
$test = new TestKBTranslation();
$test->testKBTranslation();
$test->displayKBContent();