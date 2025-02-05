

# Call of Duty Simple RCON 🎮

**Call of Duty Simple RCON** is a PHP-based Remote Console (RCON) control system for Call of Duty servers. It provides full RCON capabilities for multiple servers for Call of Duty 1, 2, 4 and world at war, allowing administrators to easily manage server settings and player actions.

This tool supports multiple server management, map rotations, user management, and other common RCON tasks. Whether you want to kick users, change maps, or adjust game modes, this system allows you to do it all from a simple web interface.

---

## ⚙️ Features

- **Full RCON Control**: Full administrative access for managing multiple Call of Duty servers.
- **Multiple Game Support**: Works with servers from Call of Duty 1, 2, 4 and world at war.
- **Map Rotations**: Create and manage custom map rotations for your server.
- **User Management**: Add, remove, and manage users with full control over their access and roles.
- **Kick Users**: Easily kick disruptive players from the server.
- **Change Maps & Game Types**: Quickly switch maps and change game types on the fly.
- **Web-Based Interface**: A user-friendly interface to control your servers directly from your browser.

---

## 🛠️ Installation

### Prerequisites

1. **Web Server**: You will need a working web server with PHP support (e.g., Apache, Nginx).
2. **Database**: A MySQL or MariaDB database for storing configuration and user data.
3. **Database User Permissions**: Ensure the database user has full permissions for `CREATE`, `SELECT`, `INSERT`, and `ALTER` operations.

### Steps

1. **Clone the Repository**:
   Clone this repository to your web server's document root:

   ```bash
   git clone https://github.com/Grimm1/Call-of-Duty-simple-RCON.git
   ```

2. **Run Installation Script**:
   Open your browser and navigate to `/utils/install.php` to run the installation process. This will set up the database and configure the system.

   Example:

   ```text
   http://your-webserver.com/utils/install.php
   ```

3. **Configure RCON Settings**:
   After installation, you can configure your RCON settings for each Call of Duty server you wish to manage. This includes adding server IPs, ports, and authentication details.

---

## 📝 License

This project is licensed under the **GPL-3.0 License**. See the [LICENSE](LICENSE) file for more details.

---

## 🚀 Getting Started

1. **Login**: Use your admin credentials to log into the web interface.
2. **Manage Servers**: Add servers and configure RCON settings.
3. **Control Servers**: Once set up, you can manage all your Call of Duty servers, including controlling user access, changing game types, and updating map rotations.

---

## 📄 Example Screenshots

![](https://github.com/Grimm1/Call-of-Duty-simple-RCON/blob/main/img_template/Simple_Rcon.gif)

---

## 🤝 Customisation

You can add custom map images to the relevant game, see the readme in the img_template folder provided by D-Toxx

---

## 🤝 Contributing

We welcome contributions! If you'd like to contribute to this project, feel free to fork the repository, submit a pull request, or open an issue if you have ideas or improvements.

---

## 🙋‍♂️ Support

For support, feel free to open an issue on the [GitHub Issues page](https://github.com/Grimm1/Call-of-Duty-simple-RCON/issues), or ask questions in the repository discussions.

---


Happy gaming and server management! 🎮
