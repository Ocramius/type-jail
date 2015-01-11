# Type Police

[![Gitter](https://badges.gitter.im/Join%20Chat.svg)](https://gitter.im/InterNations/type-police?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)

Enforce super type contract of an object

## Usage

```php
use InterNations\Component\TypePolice\Factory\PolicedProxyFactory;

$file = new SplFileObject(__FILE__);

$factory = new PolicedProxyFactory();
$file = $factory->policeInstance($file, 'SplFileInfo');
```
