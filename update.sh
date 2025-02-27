cd /var/www/html/
sudo git pull origin main
cd
sudo chmod 666 /etc/netplan/01-network-manager-all.yaml
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php-yaml

# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn\*, /bin/systemctl start openvpn\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn*, /bin/systemctl start openvpn*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick\*, /bin/systemctl start wg-quick\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick*, /bin/systemctl start wg-quick*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick\*, /bin/systemctl disable wg-quick\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick*, /bin/systemctl disable wg-quick*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(root) NOPASSWD: /usr/bin/id" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/id" >> /etc/sudoers
fi

if ! grep -q "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" >> /etc/sudoers
fi
