INTALLATION PROCEDURE
=====================

This package uses [Composer][1] to install and maintain
required PHP libraries as well as the Roundcube framework. The requirements
are basically the same as for Roundcube so please read the INSTALLATION
section in the Roundcube framework's [README][2] file.

1. Install Composer

Execute this in the project root directory:

$ curl -s http://getcomposer.org/installer | php

This will create a file named composer.phar in the project directory.

2. Install Dependencies

$ php composer.phar install

3. Import the Roundcube framework and Kolab plugins

3.1. Either copy or symlink the Roundcube framework package into lib/Roundcube
3.2. Either copy or symlink the roundcubemail-plugins-kolab into lib/plugins

4. Create local config

4.1. The configuration for this service inherits basic options from the Roundcube
config. To make that available, smylink the Roundcube config files
(main.inc.php and db.inc.php) into the local config/ directory.

4.2. Then copy the service-spcific config template:

$ cp config/dav.inc.php.sample config/dav.inc.php

Edit the local config/dav.inc.php file according to your setup and taste.
These settings override the default config options from the Roundcube
configuration.

5. Give write access for the webserver user to the 'log' folder:

$ chown <www-user> logs

6. Configure your webserver to point to the 'public_html' directory of this
package as document root.


[1]: http://getcomposer.org
[2]: https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/README.md)