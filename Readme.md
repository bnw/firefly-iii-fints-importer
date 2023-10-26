[![ci](https://github.com/bnw/firefly-iii-fints-importer/actions/workflows/publish-docker-image.yml/badge.svg)](https://github.com/bnw/firefly-iii-fints-importer/actions/workflows/publish-docker-image.yml)

This tool allows you to import transactions from your [FinTS enabled bank](https://subsembly.com/banken.html) into [Firefly III](https://www.firefly-iii.org/).  
It comes with a web GUI that guides you through the process.



Startup
-------
To start the app, choose one of the three methods below:

* Start the app by executing the following commands.
  ```
  git clone https://github.com/bnw/firefly-iii-fints-importer.git
  cd firefly-iii-fints-importer
  composer install
  php -S 0.0.0.0:8080 app/index.php
  ```

* Alternatively, you can use docker-compose. The following commands will download and start the [pre-built image from docker-hub](https://hub.docker.com/r/benkl/firefly-iii-fints-importer). 
  ```
  git clone https://github.com/bnw/firefly-iii-fints-importer.git
  cd firefly-iii-fints-importer
  docker-compose up
  ```
  To update the docker image stored on your machine to the latest version, run `docker-compose pull && docker-compose up`.

* You can also build the docker image locally. To do so, simply follow the above steps for docker-compose, but replace the line `image: benkl/firefly-iii-fints-importer` by `build: .` in the [`docker-compose.yml`](docker-compose.yml). The build usually takes a few minutes.

After completing one of the above steps, browse to http://localhost:8080 and follow the instructions 🙂


Requirements
------------
* Docker **or** (PHP 8.1 or newer and [Composer](https://getcomposer.org/))
* An already running instance of [Firefly III](https://www.firefly-iii.org/) 
  * It must be reachable over the network by PHP from the computer you run this import app
  * A _Personal Access Token_ which you can generate on the Profile page in Firefly III 


Tips
-----
* Banks typically only allow you to access the last two years worth of transactions.
  In order to ensure that your bank account shows the correct balance in Firefly III, even if not all of it's history can be imported, adjust the account's starting balance accordingly in Firefly III.
* There is the option to reformat the description of a transaction, before it is sent to Firefly III.
  This can be done by configuring a RegEx search and replace pair. See [data/configurations/example.json](https://github.com/bnw/firefly-iii-fints-importer/blob/master/data/configurations/example.json) or the [corresponding unit test](https://github.com/bnw/firefly-iii-fints-importer/blob/master/tests/TransactionsToFireflySenderTest.php) for an example. Thanks to [dfunke](https://github.com/dfunke) for this feature!
* The Firefly duplicate detection is not perfect. To minimize the number of potential duplicates, you can use `from: last` to set the date of the last transaction as the first date to import. See [data/configurations/example.json](https://github.com/bnw/firefly-iii-fints-importer/blob/master/data/configurations/example.json)


Storing configurations
----------------------

Instead of entering all necessary account information every time, you can load it from a JSON-file.  
Simply create such a JSON-file in the `data/configurations` folder by adapting the provieded [`data/configurations/example.json`](data/configurations/example.json). When starting the app in your browser, you can then choose the JSON-file as a configuration source.  
Please note that the `bank_2fa`-value in the JSON file corresponds to the number of the 2-factor authentication as listed in [`app/public/html/collecting-data.twig`](app/public/html/collecting-data.twig).  
Thanks to [joBr99](https://github.com/joBr99) for this feature!


Headless usage
-----
This importer can be used without a browser (e.g. by using `curl` or `wget`). For this you have to specify two `GET` parameters:
1. `automate=true`
1. `config=data/configurations/example.json` where this path should be exactly the same one you see on the select box

Additionally make sure that you filled out the `choose_account_automation` part in the config.  
Thanks to [Bur0k](https://github.com/Bur0k) for this feature!


Feedback
--------
So far, I could only test this with my personal bank.
If you find that it does not work with your bank, please [open an issue](https://github.com/bnw/firefly-iii-fints-importer/issues/new).


Screenshots
-----------
<img src="https://raw.githubusercontent.com/bnw/firefly-iii-fints-importer/master/docs/img/screenshots.gif" alt="Screenshots of import tool">


Warnings
-------
* Note that most banks handle failed FinTS logins similar to failed website logins. Thus, if you fail to enter your password correctly 3 times in a row, your access will most likely be blocked. Then you need to take some bank specific actions to reenable your FinTS & web access.
* For ING DiBa: According to [this wiki](https://www.willuhn.de/wiki/doku.php?id=psd2#ing), a login into the website is required every 90 days.
