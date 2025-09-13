<?php
/**
 * Test script for the threshold fix for French queries
 */

// Create a test class that simulates the API controller's getAdaptiveThreshold method
class TestThresholdFix {
    // Constants from the API controller
    private const KW_THRESHOLD_MIN = 0.02;
    private const KW_THRESHOLD_MAX = 0.15;
    private const SEM_THRESHOLD_MIN = 0.40;
    private const SEM_THRESHOLD_MAX = 0.70;
    
    // Flag to simulate French query processing
    private $simulateFrenchQuery = false;
    
    public function __construct() {
        // No dependencies needed
    }
    
    // Method to set the French query simulation flag
    public function setSimulateFrenchQuery($value) {
        $this->simulateFrenchQuery = $value;
    }
    
    // Implementation of the modified getAdaptiveThreshold function
    private function getAdaptiveThreshold(string $type, float $score, bool $isQuestion = false): float
    {
        // Use the simulation flag instead of backtrace
        $isFrenchQuery = $this->simulateFrenchQuery;
        
        if ($type === 'keyword') {
            // Lower threshold for better keyword matches, even lower for questions
            $baseThreshold = $score > 0.08 ? self::KW_THRESHOLD_MIN : self::KW_THRESHOLD_MAX;
            // Apply additional reduction for French queries (40% lower instead of 30%)
            if ($isFrenchQuery) {
                return $isQuestion ? $baseThreshold * 0.6 : $baseThreshold * 0.8; // 40% lower for French questions, 20% for other French
            }
            return $isQuestion ? $baseThreshold * 0.7 : $baseThreshold; // 30% lower for questions
        } else {
            // Adjust semantic threshold based on score confidence, lower for questions
            $baseThreshold = $score > 0.6 ? self::SEM_THRESHOLD_MIN : self::SEM_THRESHOLD_MAX;
            // Apply additional reduction for French queries (30% lower instead of 20%)
            if ($isFrenchQuery) {
                return $isQuestion ? $baseThreshold * 0.7 : $baseThreshold * 0.85; // 30% lower for French questions, 15% for other French
            }
            return $isQuestion ? $baseThreshold * 0.8 : $baseThreshold; // 20% lower for questions
        }
    }
    
    // Method to test the getAdaptiveThreshold function
    public function testThresholds() {
        echo "=== TEST DES SEUILS ADAPTATIFS ===\n\n";
        
        echo "--- SEUILS POUR LES REQUÊTES EN ANGLAIS ---\n";
        // Test thresholds for English queries
        $this->setSimulateFrenchQuery(false);
        $this->testThresholdScenario('keyword', 0.05, false, 'English non-question keyword (low score)');
        $this->testThresholdScenario('keyword', 0.05, true, 'English question keyword (low score)');
        $this->testThresholdScenario('keyword', 0.10, false, 'English non-question keyword (high score)');
        $this->testThresholdScenario('keyword', 0.10, true, 'English question keyword (high score)');
        
        $this->testThresholdScenario('semantic', 0.50, false, 'English non-question semantic (low score)');
        $this->testThresholdScenario('semantic', 0.50, true, 'English question semantic (low score)');
        $this->testThresholdScenario('semantic', 0.70, false, 'English non-question semantic (high score)');
        $this->testThresholdScenario('semantic', 0.70, true, 'English question semantic (high score)');
        
        echo "\n--- SEUILS POUR LES REQUÊTES EN FRANÇAIS ---\n";
        // Test thresholds for French queries
        $this->setSimulateFrenchQuery(true);
        $this->testThresholdScenario('keyword', 0.05, false, 'French non-question keyword (low score)');
        $this->testThresholdScenario('keyword', 0.05, true, 'French question keyword (low score)');
        $this->testThresholdScenario('keyword', 0.10, false, 'French non-question keyword (high score)');
        $this->testThresholdScenario('keyword', 0.10, true, 'French question keyword (high score)');
        
        $this->testThresholdScenario('semantic', 0.50, false, 'French non-question semantic (low score)');
        $this->testThresholdScenario('semantic', 0.50, true, 'French question semantic (low score)');
        $this->testThresholdScenario('semantic', 0.70, false, 'French non-question semantic (high score)');
        $this->testThresholdScenario('semantic', 0.70, true, 'French question semantic (high score)');
        
        echo "\n--- COMPARAISON DES SEUILS ANGLAIS VS FRANÇAIS ---\n";
        echo "Pour les requêtes non-questions avec score keyword faible (0.05):\n";
        $this->setSimulateFrenchQuery(false);
        $thresholdEn = $this->getAdaptiveThreshold('keyword', 0.05, false);
        $this->setSimulateFrenchQuery(true);
        $thresholdFr = $this->getAdaptiveThreshold('keyword', 0.05, false);
        echo sprintf("  - Anglais: %.4f\n  - Français: %.4f\n  - Différence: %.1f%%\n", 
                    $thresholdEn, $thresholdFr, (1 - $thresholdFr/$thresholdEn) * 100);
        
        echo "Pour les questions avec score sémantique élevé (0.70):\n";
        $this->setSimulateFrenchQuery(false);
        $thresholdEn = $this->getAdaptiveThreshold('semantic', 0.70, true);
        $this->setSimulateFrenchQuery(true);
        $thresholdFr = $this->getAdaptiveThreshold('semantic', 0.70, true);
        echo sprintf("  - Anglais: %.4f\n  - Français: %.4f\n  - Différence: %.1f%%\n", 
                    $thresholdEn, $thresholdFr, (1 - $thresholdFr/$thresholdEn) * 100);
    }
    
    // Helper method to test a specific threshold scenario
    private function testThresholdScenario($type, $score, $isQuestion, $description) {
        // Call the getAdaptiveThreshold method
        $threshold = $this->getAdaptiveThreshold($type, $score, $isQuestion);
        $isFrench = $this->simulateFrenchQuery;
        
        // Output the result
        echo sprintf(
            "%s: type=%s, score=%.2f, isQuestion=%s, isFrench=%s => threshold=%.4f\n",
            $description,
            $type,
            $score,
            $isQuestion ? 'true' : 'false',
            $isFrench ? 'true' : 'false',
            $threshold
        );
    }
}

// Run the test
$test = new TestThresholdFix();
$test->testThresholds();