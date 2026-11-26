<div align="center">

# 🤖 PrestaShop AI Chatbot

**An AI shopping assistant for PrestaShop stores: FAQ answers, product search, promotions and order tracking**

![PHP](https://img.shields.io/badge/PHP_8.1-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PrestaShop](https://img.shields.io/badge/PrestaShop-DF0067?style=for-the-badge&logo=prestashop&logoColor=white)
![OpenAI](https://img.shields.io/badge/OpenAI_API-412991?style=for-the-badge&logo=openai&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-885630?style=for-the-badge&logo=composer&logoColor=white)

</div>

## Overview

A PrestaShop module that adds a chatbot to an online store. It answers customers in **French and English**, finds products, checks prices, stock and promotions **live from the store database**, and gives order status after verifying the customer's email.

FAQ answers use **OpenAI embeddings** combined with **BM25 keyword scoring**, so the bot finds the right answer even when the question is phrased differently. When no OpenAI key is set, it falls back to database-driven answers.

I built it during my AI developer internship at a web & AI agency.

## Features

- **Semantic FAQ search**: OpenAI embeddings + BM25 IDF weights, built from TXT or PDF knowledge files
- **Live product search**: prices, stock and categories straight from PrestaShop tables
- **Promotions**: understands "any products on sale?" / "des produits en promo ?" and lists discounts
- **Secure order tracking**: order status only after email verification
- **Multilingual**: French and English, with automatic translation
- **Admin panel**: API key settings and FAQ file upload from the PrestaShop back office

## Architecture

```
Customer ──► Chat widget (hook template) ──► Front API controller
                                                │
              ┌─────────────────────────────────┼──────────────────────────┐
              ▼                                 ▼                          ▼
     FAQ engine (embeddings + BM25)   PrestaShop DB (products,     OpenAI Chat API
     from uploaded TXT/PDF files      categories, orders)          (optional)
```

## Tech stack

| Layer | Technologies |
|---|---|
| Module | PHP 8.1, PrestaShop module API, Guzzle |
| AI | OpenAI Chat Completions and Embeddings, BM25 ranking |
| Data | PrestaShop MySQL tables |
| Other | Google Translate (PHP), Composer |

## Getting started

```bash
composer install --no-dev --optimize-autoloader
```

1. Zip the folder as `ai_chatbot` and upload it in **Modules → Module Manager → Upload a module**.
2. Open the module settings, add your OpenAI API key and upload your FAQ file.
3. Optional: build the FAQ embeddings from the command line:

```bash
php faq_embed_admin.php uploads/faq.txt YOUR_OPENAI_API_KEY
```

The FAQ files in `uploads/` are sample data.

## Project structure

```
ai_chatbot.php            module class: install, configuration, FAQ uploads
controllers/front/api.php chatbot API: intents, FAQ matching, products, orders
controllers/admin/        back-office settings
faq_embed_admin.php       builds embeddings + BM25 weights from FAQ files
views/templates/          chat widget and admin templates
tests/                    context and translation tests
```

---

Built by [Ayman Dhib](https://www.linkedin.com/in/ayman-dhib/)
