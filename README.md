# http_upload_component
External component for XEP-0363 (HTTP File Upload) written in PHP.

It runs as a stand alone process on the same host as your XMPP server and
connects to that server using the [Jabber Component
Protocol](http://xmpp.org/extensions/xep-0114.html).

A detailed introduction into the necessity of such a component and the simple
protocol can be found in [XEP-0363: HTTP File Upload](http://xmpp.org/extensions/xep-0363.html).

### Prerequisites
You need a webserver capable of serving php files and be able to use php as cli as well.

The webserver needs to be capable to reroute requests in the subdirectory of the component to the index.php file.
An example Apache .htaccess file to accomplish this is included.

The code runs under php 5.4 and above (not sure if it does run on php 7, though).

### Installation
To install this component you have to copy the whole repository to a directory of your web server (preferably an TLS-enabled one).

That's all.

### Configuration
To configure the component you have to change the values in the component/config.ini file according to your needs.
This file is heavily commented to ease this task.

Make sure the file you configure via slot_db is readable and writable by the user the webserver executes php scripts under and also by the user you run your component.

After that you should configure an external component in your xmpp server for the same host and password you configured in config.ini (component_host and component_password) .

### Running
To use the component you should start it at boot time with something like this:

php /var/www/component/component.php >> /var/log/httpuploadcomponent.log 2>&1

### License
This project is licenced under the MIT license.

It also uses a (briefly modified) version of [JAXL](https://github.com/jaxl/JAXL/). See the license file under component/jaxl for the license of this library.
