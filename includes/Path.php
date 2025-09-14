<?php

class AiPaths
{
    public static function uploadsDir(): string
    {
        return rtrim(_PS_MODULE_DIR_ . 'ai_chatbot/uploads', '/');
    }

    public static function embeddingsPath(string $kbBasename): string
    {
        return self::uploadsDir() . '/' . $kbBasename . '.embeddings.json';
    }
}
