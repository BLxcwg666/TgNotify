# TgNotify
将  `Typecho` 的评论推送至 `Telegram` 通知，支持回复评论、通过评论、垃圾评论和删除评论  
修改自 https://github.com/Adoream/typecho-plugin-comment2telegram

## 使用
1. 下载后请将插件目录名请修改为 `TgNotify`
2. 上传至服务器，启用插件
3. 然后填写 Telegram Bot Token 和 Telegram ID（Telegram ID 可通过 Start `@zuimoe_Bot`，发送 `/Info` 获得（暂不可用的说
4. Enjoy ~

PS：如果不知道如何获取 `Telegram Bot Token` 的话那么。。。你不适合使用 `Comment2Telegram`

## 截图
![image](https://github.com/BLxcwg666/TgNotify/assets/66854530/c017aeee-45be-4495-8544-a64091174b56)

## 注意
1. 由于 Telegram 官方的限制，当使用 `插件处理` 时，请使用 HTTPS 模式
2. 使用`回复评论`时，请保持 Telegram UserName 与 Typecho UserName 一致，否则将会无法提交评论
3. 为了更好的了解使用情况，在插件中添加了统计代码，假如想要去除请删除 `Plugin.php` 文件中的 29～34 行与 50～53 行

## 关于 `回复处理`
建议直接使用插件进行处理，如果Bot还需实现有其他功能的话。。就选择 `外部处理`

