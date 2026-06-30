# EventHub Pro — MVP
### Examen PHP Avancé · ENSA Marrakech · Université Cadi Ayyad

---

## 🚀 Installation rapide

### 1. Prérequis
- PHP 8.1+
- MySQL 8.0+
- XAMPP / WAMP / Laragon
- Composer (recommandé pour PHPMailer)

### 2. Base de données
```bash
mysql -u root -p < database/schema.sql
```
Puis complétez `database/schema.sql` (Partie 1.1).

### 3. Configuration
Éditez `config/db.php` :
```php
define('DB_NAME', 'eventhub_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Éditez `config/mailer.php` avec vos credentials SMTP (ex : Mailtrap).

### 4. Bibliothèques PDF
Déposez TCPDF **ou** Dompdf dans `lib/` :
```
lib/
  tcpdf/tcpdf.php          ← si vous choisissez TCPDF
  phpqrcode/qrlib.php      ← QR Code (obligatoire)
vendor/autoload.php        ← si vous utilisez Composer + Dompdf
```

### 5. Lancer
```
http://localhost/eventhub_mvp/
```

---

## 📁 Structure du projet

```
eventhub_mvp/
├── index.html                  
├── config/
│   ├── db.php                  
│   └── mailer.php              
├── events/
│   ├── create.php              
│   └── register.php            
├── api/
│   ├── events.php              
│   └── stats.php               
├── pdf/
│   ├── ticket.php              
│   └── report.php              
├── mail/
│   ├── SendConfirmation.php    
│   ├── AlertMailer.php         
│   └── templates/
│       ├── confirmation.html   
│       └── alert.html          
├── assets/
│   └── js/app.js               
├── database/
│   └── schema.sql              
└── CHOIX_TECHNIQUES.md         
```


