<?php

class TranslationTester {
    private $debug = true;
    
    /**
     * Fonction de log pour le débogage
     */
    private function log($message) {
        if ($this->debug) {
            echo "[LOG] $message\n";
        }
    }
    
    /**
     * Détection de langue avancée
     */
    public function detectLanguageAdvanced(string $text): string {
        // Si le texte est trop court, par défaut en anglais pour éviter les erreurs de détection
        if (mb_strlen(trim($text), 'UTF-8') < 3) {
            $this->log("LANG DETECT: Text too short, defaulting to English");
            return 'en';
        }
        
        $originalText = $text;
        $text = mb_strtolower($text, 'UTF-8');
        
        // Indicateurs forts de français (haute confiance)
        $strongFrench = [
            // Articles définis et pronoms
            '/\b(je|tu|vous|nous|ils|elles|on|mon|ma|mes|ton|ta|tes|son|sa|ses)\b/u',
            // Mots français courants
            '/\b(avec|dans|pour|sans|comme|depuis|pendant|avant|après|chez)\b/u',
            // Conjugaisons spécifiques au français
            '/\b(suis|êtes|sont|avons|avez|ont|vais|vas|allons|allez|vont)\b/u',
            // Mots interrogatifs français
            '/\b(comment|pourquoi|combien|quand|où|quel|quelle|quels|quelles)\b/u',
            // Connecteurs français
            '/\b(mais|donc|puis|alors|ensuite|cependant|néanmoins)\b/u',
            // Indicateurs français supplémentaires
            '/\b(est|sont|c\'est|ce|cette|ces|cet|de|du|des|la|le|les|un|une)\b/u'
        ];
        
        $frenchScore = 0;
        foreach ($strongFrench as $pattern) {
            if (preg_match($pattern, $text)) {
                $frenchScore += 2; // Poids plus élevé
                $this->log("LANG DETECT: French pattern match: $pattern");
            }
        }
        
        // Vérifier les caractères accentués français (indicateur très fort)
        if (preg_match('/[àâäéèêëîïôöùûüç]/u', $originalText)) {
            $frenchScore += 4; // Indicateur très fort
            $this->log("LANG DETECT: French accents detected");
        }
        
        // Salutations françaises et mots polis
        if (preg_match('/\b(bonjour|salut|bonsoir|merci|pardon|excusez|désolé|s\'il|sil)\b/u', $text)) {
            $frenchScore += 3;
            $this->log("LANG DETECT: French greetings detected");
        }
        
        // Termes spécifiques français et mots courants
        if (preg_match('/\b(commande|livraison|retour|prix|produit|article|achat|couleur|taille|marque)\b/u', $text)) {
            $frenchScore += 2;
            $this->log("LANG DETECT: French e-commerce terms detected");
        }
        
        // Phrases courtes françaises très spécifiques
        if (preg_match('/\b(quoi|quoi\?|oui|non|peut-être|peut être|d\'accord|ok)\b/u', $text)) {
            $frenchScore += 1;
            $this->log("LANG DETECT: French short phrases detected");
        }
        
        // Question française sur les produits
        if (preg_match('/\b(avez-vous des|avez vous des)\b/u', $text)) {
            $frenchScore += 5; // Indicateur très fort pour le français
            $this->log("LANG DETECT: French product question detected");
        }
        
        // Indicateurs anglais (pour comparaison)
        $strongEnglish = [
            '/\b(the|and|or|but|with|from|have|has|will|would|could|should|this|that|these|those)\b/u',
            '/\b(order|delivery|return|price|product|item|purchase|tracking|hello|hi|yes|no|thanks|thank)\b/u',
            '/\b(what|where|when|how|why|who|which|can|do|does|is|are|was|were)\b/u'
        ];
        
        $englishScore = 0;
        foreach ($strongEnglish as $pattern) {
            if (preg_match($pattern, $text)) {
                $englishScore += 2;
                $this->log("LANG DETECT: English pattern match");
            }
        }
        
        $this->log("LANG DETECT: FR={$frenchScore}, EN={$englishScore} for: \"" . substr($text, 0, 50) . '..."');
        
        // Augmenter le seuil pour la détection du français pour éviter les faux positifs
        // Détecter comme français uniquement si le score est significativement plus élevé que l'anglais
        return ($frenchScore > $englishScore && $frenchScore > 0) ? 'fr' : 'en';
    }
    
    /**
     * Tokenisation du texte
     */
    public function tok(string $text): array {
        $text = mb_strtolower($text, 'UTF-8');
        $noAcc = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($noAcc !== false) $text = $noAcc;
        $text = preg_replace('/[^0-9a-z\s]/i', ' ', $text);
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return [];
        
        static $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'for', 'to', 'is', 'are', 'on', 'in', 'with', 'do', 'you', 'any',
                      'la', 'le', 'les', 'de', 'des', 'du', 'et', 'ou', 'au', 'aux', 'un', 'une'];
        
        $tokens = array_values(array_unique(array_diff($parts, $stop)));
        $this->log("TOKENS: " . json_encode($tokens));
        return $tokens;
    }
    
    /**
     * Expansion des variations de mots
     */
    public function expandWordVariations(array $tokens): array {
        $expanded = $tokens;
        foreach ($tokens as $token) {
            // Variations pluriel/singulier en anglais
            if (preg_match('/^(.+)s$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1]; // shirts -> shirt
                $this->log("EXPAND: $token -> {$m[1]}");
            } elseif (!preg_match('/s$/', $token)) {
                $expanded[] = $token . 's'; // shirt -> shirts
                $this->log("EXPAND: $token -> {$token}s");
            }
            
            // Gestion des pluriels irréguliers courants
            $irregulars = [
                'child' => 'children', 'children' => 'child',
                'man' => 'men', 'men' => 'man',
                'woman' => 'women', 'women' => 'woman',
                'foot' => 'feet', 'feet' => 'foot',
                'shoe' => 'shoes', 'shoes' => 'shoe',
                'mouse' => 'mice', 'mice' => 'mouse',
                'person' => 'people', 'people' => 'person',
                'produit' => 'produits', 'produits' => 'produit',
                'chaussure' => 'chaussures', 'chaussures' => 'chaussure',
                'vetement' => 'vetements', 'vetements' => 'vetement',
                'vêtement' => 'vêtements', 'vêtements' => 'vêtement'
            ];
            
            if (isset($irregulars[$token])) {
                $expanded[] = $irregulars[$token];
                $this->log("EXPAND IRREGULAR: $token -> {$irregulars[$token]}");
            }
        }
        
        return array_values(array_unique($expanded));
    }
    
    /**
     * Simulation de traduction pour le traitement
     */
    public function translateForProcessing(string $userInput, string $userLang): string {
        $this->log("TRANSLATE FOR PROCESSING: Input language: $userLang, input: \"" . substr($userInput, 0, 50) . '..."');
        
        // Traduire uniquement l'entrée française en anglais pour le traitement
        if ($userLang === 'fr') {
            $this->log('TRANSLATE FOR PROCESSING: Translating French to English for processing');
            
            // Simulation de traduction (dans un environnement réel, cela utiliserait l'API de traduction)
            $translations = [
                'comment retourner un produit' => 'how to return a product',
                'quelle est votre politique de retour' => 'what is your return policy',
                'comment contacter le service client' => 'how to contact customer service',
                'quels sont les délais de livraison' => 'what are the delivery times',
                'avez-vous des magasins physiques' => 'do you have physical stores',
                'je cherche des chaussures blanches' => 'i am looking for white shoes'
            ];
            
            $lowerInput = mb_strtolower(trim($userInput), 'UTF-8');
            foreach ($translations as $fr => $en) {
                if (strpos($lowerInput, $fr) !== false) {
                    $this->log("TRANSLATE: Found match for '$fr' -> '$en'");
                    return $en;
                }
            }
            
            // Si aucune correspondance exacte n'est trouvée, simuler une traduction basée sur les tokens
            $tokens = $this->tok($userInput);
            $translatedTokens = [];
            
            $wordTranslations = [
                'produit' => 'product',
                'produits' => 'products',
                'retour' => 'return',
                'retours' => 'returns',
                'politique' => 'policy',
                'livraison' => 'delivery',
                'delai' => 'time',
                'delais' => 'times',
                'contacter' => 'contact',
                'service' => 'service',
                'client' => 'customer',
                'magasin' => 'store',
                'magasins' => 'stores',
                'physique' => 'physical',
                'physiques' => 'physical',
                'cherche' => 'looking for',
                'chaussure' => 'shoe',
                'chaussures' => 'shoes',
                'blanc' => 'white',
                'blanche' => 'white',
                'blanches' => 'white'
            ];
            
            foreach ($tokens as $token) {
                $translatedTokens[] = $wordTranslations[$token] ?? $token;
            }
            
            $translated = implode(' ', $translatedTokens);
            $this->log("TRANSLATE: Token-based translation: '$translated'");
            return $translated;
        }
        
        $this->log('TRANSLATE FOR PROCESSING: No translation needed, returning original');
        return $userInput;
    }
    
    /**
     * Test de la chaîne de traitement complète
     */
    public function testTranslationChain() {
        echo "=== TEST DE LA CHAÎNE DE TRADUCTION ===\n\n";
        
        $testQueries = [
            'Comment retourner un produit?' => 'fr',
            'Quelle est votre politique de retour?' => 'fr',
            'Avez-vous des magasins physiques?' => 'fr',
            'Je cherche des chaussures blanches' => 'fr',
            'How do I return a product?' => 'en',
            'What is your return policy?' => 'en',
            'Do you have physical stores?' => 'en',
            'I am looking for white shoes' => 'en'
        ];
        
        foreach ($testQueries as $query => $expectedLang) {
            echo "\n--- Test avec: '$query' ---\n";
            
            // 1. Détection de langue
            $detectedLang = $this->detectLanguageAdvanced($query);
            echo "1. Langue détectée: $detectedLang (attendu: $expectedLang)\n";
            
            // 2. Tokenisation
            $tokens = $this->tok($query);
            echo "2. Tokens: " . json_encode($tokens) . "\n";
            
            // 3. Expansion des tokens
            $expandedTokens = $this->expandWordVariations($tokens);
            echo "3. Tokens étendus: " . json_encode($expandedTokens) . "\n";
            
            // 4. Traduction pour traitement
            $translated = $this->translateForProcessing($query, $detectedLang);
            echo "4. Traduit pour traitement: '$translated'\n";
            
            // 5. Tokenisation après traduction
            $translatedTokens = $this->tok($translated);
            echo "5. Tokens après traduction: " . json_encode($translatedTokens) . "\n";
            
            // 6. Expansion des tokens après traduction
            $expandedTranslatedTokens = $this->expandWordVariations($translatedTokens);
            echo "6. Tokens étendus après traduction: " . json_encode($expandedTranslatedTokens) . "\n";
            
            echo "---\n";
        }
    }
    
    /**
     * Test de simulation de recherche dans la KB
     */
    public function testKBSearch() {
        echo "\n=== TEST DE SIMULATION DE RECHERCHE DANS LA KB ===\n\n";
        
        // Simuler une base de connaissances simple
        $kb = [
            [
                'q' => 'How do I return a product?',
                'a' => 'To return a product, please visit our returns page and fill out the form. You have 30 days to return any item.',
                'qt' => ['return', 'product'],
                'at' => ['return', 'product', 'visit', 'returns', 'page', 'fill', 'form', 'days', 'item']
            ],
            [
                'q' => 'What is your return policy?',
                'a' => 'Our return policy allows returns within 30 days of purchase with original receipt. Refunds are processed within 5-7 business days.',
                'qt' => ['return', 'policy'],
                'at' => ['return', 'policy', 'allows', 'returns', 'days', 'purchase', 'original', 'receipt', 'refunds', 'processed', 'business', 'days']
            ],
            [
                'q' => 'Do you have physical stores?',
                'a' => 'Yes, we have physical stores in major cities. You can find the nearest store using our store locator on the website.',
                'qt' => ['physical', 'stores'],
                'at' => ['physical', 'stores', 'major', 'cities', 'find', 'nearest', 'store', 'using', 'store', 'locator', 'website']
            ],
            [
                'q' => 'What are the delivery times?',
                'a' => 'Standard delivery takes 3-5 business days. Express delivery is available for 1-2 business days at an additional cost.',
                'qt' => ['delivery', 'times'],
                'at' => ['standard', 'delivery', 'takes', 'business', 'days', 'express', 'delivery', 'available', 'business', 'days', 'additional', 'cost']
            ]
        ];
        
        $testQueries = [
            'Comment retourner un produit?' => 'fr',
            'Quelle est votre politique de retour?' => 'fr',
            'Avez-vous des magasins physiques?' => 'fr',
            'Quels sont les délais de livraison?' => 'fr',
            'How do I return a product?' => 'en',
            'What is your return policy?' => 'en',
            'Do you have physical stores?' => 'en',
            'What are the delivery times?' => 'en'
        ];
        
        foreach ($testQueries as $query => $lang) {
            echo "\n--- Test de recherche avec: '$query' (langue: $lang) ---\n";
            
            // 1. Détection de langue
            $detectedLang = $this->detectLanguageAdvanced($query);
            echo "1. Langue détectée: $detectedLang\n";
            
            // 2. Traduction pour traitement si nécessaire
            $searchQuery = $detectedLang === 'fr' ? $this->translateForProcessing($query, $detectedLang) : $query;
            echo "2. Query pour recherche: '$searchQuery'\n";
            
            // 3. Tokenisation
            $tokens = $this->tok($searchQuery);
            echo "3. Tokens: " . json_encode($tokens) . "\n";
            
            // 4. Recherche simple par correspondance de tokens
            $bestMatch = null;
            $bestScore = 0;
            
            foreach ($kb as $entry) {
                $score = 0;
                $allTokens = array_merge($entry['qt'], $entry['at']);
                
                foreach ($tokens as $token) {
                    if (in_array($token, $allTokens)) {
                        $score++;
                    }
                }
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $entry;
                }
            }
            
            echo "4. Meilleur score: $bestScore\n";
            
            if ($bestMatch && $bestScore > 0) {
                echo "5. Réponse trouvée: '{$bestMatch['a']}'\n";
                
                // 6. Traduction de la réponse si nécessaire
                if ($lang === 'fr') {
                    // Simulation de traduction de la réponse
                    $translatedAnswer = "[Traduction simulée] " . $bestMatch['a'];
                    echo "6. Réponse traduite: '$translatedAnswer'\n";
                }
            } else {
                echo "5. Aucune réponse trouvée\n";
            }
            
            echo "---\n";
        }
    }
}

// Exécuter les tests
$tester = new TranslationTester();
$tester->testTranslationChain();
$tester->testKBSearch();