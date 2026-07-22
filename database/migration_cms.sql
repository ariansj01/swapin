CREATE TABLE IF NOT EXISTS content_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,

    content LONGTEXT NOT NULL,

    featured_image VARCHAR(255) DEFAULT NULL,

    meta_title VARCHAR(255) DEFAULT NULL,
    meta_description TEXT DEFAULT NULL,

    canonical_url VARCHAR(500) DEFAULT NULL,

    faq_json LONGTEXT DEFAULT NULL,

    internal_links LONGTEXT DEFAULT NULL,

    status ENUM('draft','published') DEFAULT 'draft',

    index_status ENUM('index','noindex') DEFAULT 'index',

    created_by INT UNSIGNED DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
        ON UPDATE CURRENT_TIMESTAMP
);


