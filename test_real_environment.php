<?php
/**
 * Test script for the threshold fix for French queries in a real environment
 */

// Include the API controller
require_once __DIR__ . '/controllers/front/api.php';

// Create a test class that extends the API controller to access protected methods
class TestRealEnvironment {
    private $apiController;
    
    public function __construct() {
        // Create an instance of the API controller
        $this->apiController = new AiChatbotApiModuleFrontController();
    }
    
    // Method to test the searchFAQ function with French and English queries
    public function testSearchFAQ() {
        echo "=== TEST DE RECHERCHE FAQ DANS L'ENVIRONNEMENT RÉEL ===\n\n";
        
        // Test queries in English
        echo "--- REQUÊTES EN ANGLAIS ---\n";
        $this->testQuery('Do you have physical stores?', 'en');
        $this->testQuery('What is your return policy?', 'en');
        $this->testQuery('How can I track my order?', 'en');
        
        echo "\n--- REQUÊTES EN FRANÇAIS ---\n";
        $this->testQuery('Avez-vous des magasins physiques?', 'fr');
        $this->testQuery('Quelle est votre politique de retour?', 'fr');
        $this->testQuery('Comment puis-je suivre ma commande?', 'fr');
    }
    
    // Helper method to test a specific query
    private function testQuery($query, $lang) {
        echo "Query: '$query' (langue: $lang)\n";
        
        try {
            // Call the searchFAQ method using reflection
            $result = $this->callProtectedMethod($this->apiController, 'searchFAQ', [$query, $lang]);
            
            if ($result) {
                echo "Réponse trouvée: '" . substr($result, 0, 100) . (strlen($result) > 100 ? '...' : '') . "'\n";
            } else {
                echo "Aucune réponse trouvée.\n";
            }
        } catch (Exception $e) {
            echo "Erreur: " . $e->getMessage() . "\n";
        }
        
        echo "---\n";
    }
    
    // Helper method to call protected methods using reflection
    private function callProtectedMethod($object, $methodName, array $parameters = []) {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($object, $parameters);
    }
}

// Run the test
try {
    $test = new TestRealEnvironment();
    $test->testSearchFAQ();
} catch (Exception $e) {
    echo "Erreur d'initialisation: " . $e->getMessage() . "\n";
}