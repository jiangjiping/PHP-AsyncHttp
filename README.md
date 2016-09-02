注：如果您的项目需要异步http，请联系609176445@qq.com！^_^
1. 需要安装扩展libevent.

2. 需要配置redis，在AsyncHttp.php头部define定义

3. 启动：该插件需要在cli命令行启动
   /usr/local/php/bin/php AsyncHttp.php start
4. 关闭：
   ps aux | grep AsyncHttp.php
   找到对应的进程id
   kill -s 9 $PID
   
