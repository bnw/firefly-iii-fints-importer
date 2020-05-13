This tool allows you to import transactions from your [FinTS enabled bank](https://subsembly.com/banken.html) into [Firefly III](https://www.firefly-iii.org/).  
It comes with a web GUI that guides you through the process.

Usage
-----

Start the app by executing the following commands.
```
git clone https://github.com/bnw/firefly-iii-fints-importer.git
cd firefly-iii-fints-importer
composer install
php -S 0.0.0.0:8080 app/index.php
```

Alternatively, you can use docker-compose:
```
git clone https://github.com/bnw/firefly-iii-fints-importer.git
cd firefly-iii-fints-importer
docker-compose up
```

Browse to http://localhost:8080 and follow the instructions 🙂


Requirements
------------
* PHP 7.3 or newer
* [Composer](https://getcomposer.org/)
* An already running instance of [Firefly III](https://www.firefly-iii.org/) 
  * It must be reachable over the network by PHP from the computer you run this import app
  * A Personal Access Token which you can generate on the Profile page in Firefly III 
  
  
Feedback
--------
So far, I could only test this with my personal bank.
If you find that it does not work with your bank, please [open an issue](https://github.com/bnw/firefly-iii-fints-importer/issues/new).

  
Screenshots
-----------
<img src="https://raw.githubusercontent.com/bnw/firefly-iii-fints-importer/master/docs/img/screenshots.gif" alt="Screenshots of import tool">
