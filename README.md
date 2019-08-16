# ChromeHeadless.io - The PDF Generator Cloud Service

### Focus on your application, save your time & server resources by off-loading the heavy PDF generation tasks to us. ChromeHeadless.io will deliver the beautiful and error-free PDFs for your professional customer's invoice, data reports and more..

## How it works?

Our service is provided through REST APIs so virtually it can be used with any language and system. For convenience, we are constructing the client library for each of languages such as PHP. NodeJs, .Net and Python. So if you are using those languages, you may install the client library and provide it with your secret token created from your registered account. The client library will compress your HTML together with resources such as CSS and Javascript and send over to our server farms to generate PDF version. The high definition PDF will be sent back to you.

## Advantages

There are number of advantages of using our system:

1. __No installation required:__ You do not have to install PhantomJS or Headless Chrome.
2. __Off-load heavy task:__ Headless Chrome or PhantomJS required certain amount of CPUs and RAM which you may reserve for other crucial tasks. Beside your CPUs and RAM may not be optimized for this tasks which may affect the efficiency. Our system contains a farm of servers which is highly optimized so we can perform this tasks faster and better.
3. __Avoid complicated interface:__ You may avoid unnecessarily complicated coding to control headless browser.
4. __Everything works smoothly:__ We will take care to make sure that your PDFs generated nicely,all fonts are working so that you can spend more time concentrate on your application rather than be bugged with Headless Chrome issues.

### Our advantages over other similar PDF cloud services

We have studied well other services before we decided to move on with our services, here are some of our advantages over them:

1. Some of them do not execute Javascript, only converting pure HTML to PDF but __WE DO__!
2. Many of them require your application to be online so that resources can be loaded. If your application is in localhost or intranet their solution will not work but __WE DO__!

## Get Token Key

Token key is a __secret string__ with 64 characters used to access our service. You need to attach your token in every request you make to our service.

__Steps to generate token key:__

1.  If you have account with us, go to step 2 otherwise [sign-up](https://chromeheadless.io/#signup) with us. An email with title _"Welcome to ChromeHeadless.io"_ will be sent to you in few minutes after your sign up. 
2. Use account credential in welcome email to [log in](https://chromeheadless.io/login) our system.
3. Go to [tokens management](https://chromeheadless.io/account/tokens) page
4. Hit `Generate` button to generate token key.

## Installation

The __PHP Client__ can be installed through composer

```bash
composer require chromeheadlessio/php-client
```

## Example

```php
<?php
//Use PHP Client Library
require_once "vendor/autoload.php";

//Create ChromeHeadless service with your token key specified
$service = new \chromeheadlessio\Service("my-token-key");

//Get PDF generated from html content and push it to browser
$service->export([
    "html"=>"Hello world!"
])->pdf([
    "format"=>"A4",
    "orientation"=>"portrait"
])->sendToBrowser("helloworld.pdf");
```

## Exporting content

The `export()` method belongs the service class. It receives an array as parameter defining what you need to export. Below are list of properties:

|Name|Type|Default|Description|
|---|---|---|---|
|`html`|string||The html you want to convert|
|`httpHost`|string|"localhost"|If set the `httpHost` and the `baseUrl` will be used to replace the resource link within html|
|`baseUrl`|string||The location which the html file should be in virtually|
|`url`|string||If `html` is not set and `url` is set instead then the url will used by php client|
|`timeout`|number|30|Maximum navigation time in milliseconds, defaults to 30 seconds, pass 0 to disable timeout.|
|`waitUntil`|string|"load"|When to consider navigation succeeded. Other options are `"domcontentloaded"` page finished when all DOM is loaded; `"networkidle0"` page finished when there are no more than 0 network connections for at least 500 ms; `"networkidle2"` page finished when  there are no more than 2 network connections for at least 500 ms.|

## Export to PDF

The `pdf()` method will help to generate pdf file. It takes an array as parameter defining options for your PDF. Below are available options.

|Name|Type|Default|Description|
|---|---|---|---|
|`scale`|number|1|Scale of the webpage rendering. Defaults to 1. Scale amount must be between 0.1 and 2|
|`displayHeaderFooter`|bool|false|Display header and footer.|
|`headerTemplate`|string||HTML template for the print header. Should be valid HTML markup with following classes used to inject printing values into them: `pageNumber` current page number; `totalPages` total pages in the document; |
|`footerTemplate`|string||HTML template for the print footer. Should use the same format as the `headerTemplate`|
|`printBackground`|bool|false|Print background graphics.|
|`landscape`|bool|false|Paper orientation.|
|`pageRanges`|string||Paper ranges to print, e.g., '1-5, 8, 11-13'. Defaults to the empty string, which means print all pages.|
|`format`|string||Paper format. If set, takes priority over width or height options. Defaults to 'Letter'.|
|`width`|string/number||Paper width, accepts values labeled with units.|
|`height`|string/number||Paper height, accepts values labeled with units.|
|`margin`|object||Paper margins, defaults to none. It has 4 sub properties: `top`, `right`, `bottom`, `left` which can take number or string with units|

__Example:__

```
$service->export(...)->pdf([
    "scale"=>1,
    "format"=>"A4",
    "landscape"=>true
])->sendToBrowser("myfile.pdf");
```

### PDF options in view file

Some options could be set directly in the PDF view file instead of pdf() method.

#### header and footer

In the view file, use header and footer tags to set pdf's header and footer template:

__Example:__

```
<header>
    <div id="header-template" 
        style="font-size:10px !important; color:#808080; padding-left:10px">
        <span>Header: </span>
        {date}
        {title}
        {url}
        {pageNumber}
        {totalPages}
        <span id='pageNum' class="pageNumber"></span>
        <img src='http://www.chromium.org/_/rsrc/1438879449147/config/customLogo.gif?revision=3' />
    </div>
</header>
<footer>
...
</footer>
```
if either header or footer tag exists, pdf options' displayHeaderFooter will be true. PDF options' headerTemplate and footerTemplate options take priority over view file's header and footer tags. With header and footer tags, if there's no font-size style, a default style "font-size:10x" is used. Header and footer tags supports place holders like {date}, {title}, etc and img tag with link-type src. For img tag pdf options' headerTemplate and footerTemplate only support base64-type src.

#### margin

In the view file, use the body tag's margin style to set pdf margin:

__Example:__

```
//MyReportPDF.view.php
<body style='margin: 1in 0.5in 1in 0.5in'>
...
</body>

```
If either header or footer tag exists but there's no body's margin, a default margin of 1 inch will be used

## Export to PNG

The `png()` help to generate PNG file. It take an array as parameter defining options for your PNG. Below are list of properties:

|Name|Type|Default|Description|
|---|---|---|---|
|`fullPage`|bool|false|When true, takes a screenshot of the full scrollable page.|
|`clip`|object||An object which specifies clipping region of the page. Should have the following fields: `x` is the x-coordinate of top-left corner of clip area, `y` is y-coordinate of top-left corner of clip area, `width` is the width of clipping area and `height` is the height of clipping area.|
|`omitBackground`|bool|false|Hides default white background and allows capturing screenshots with transparency. |
|`encoding`|string|"binary"|The encoding of the image, can be either `base64` or `binary`|

__Example:__

```
$service->export(...)->png([
    "clip"=>[
        "x"=>100,
        "y"=>100,
        "width"=>500,
        "height"=>1000,
    ]
])->sendToBrowser("myfile.png");
```

## Export to JPG

The `png()` help to generate JPG file. It take an array as parameter defining options for your JPG. Below are list of properties:

|Name|Type|Default|Description|
|---|---|---|---|
|`quality`|number||The quality of the image, between 0-100.|
|`fullPage`|bool|false|When true, takes a screenshot of the full scrollable page.|
|`clip`|object||An object which specifies clipping region of the page. Should have the following fields: `x` is the x-coordinate of top-left corner of clip area, `y` is y-coordinate of top-left corner of clip area, `width` is the width of clipping area and `height` is the height of clipping area.|
|`omitBackground`|bool|false|Hides default white background and allows capturing screenshots with transparency. |
|`encoding`|string|"binary"|The encoding of the image, can be either `base64` or `binary`|

__Example:__

```
$service->export(...)->jpg([
    "quality"=>80
    "clip"=>[
        "x"=>100,
        "y"=>100,
        "width"=>500,
        "height"=>1000,
    ]
])->sendToBrowser("myfile.jpg");
```

## Getting result

In all above examples we use method `sendToBrowser()` to send the file to browser for user to open on browser or download as attachment. Here are all options:

|Method|Return|Description|
|---|---|---|
|`sendToBrowser($filename, $inlineOrAttachment)`||Send file to client browser to open on browser or download as attachment. Default value is "attachment"|
|`toString()`|string|Return filename as string|
|`toBase64()`|string|Return content of file in base64|
|`save($path)`||Save the file to specific location|

__Examples:__

```
$service->export(...)->jpg([
    "quality"=>80
    "clip"=>[
        "x"=>100,
        "y"=>100,
        "width"=>500,
        "height"=>1000,
    ]
])->save("../img/myfile.jpg");
```

# About us

__KoolPHP Inc__ has been in business for 10 years, we focus on building the featured rich yet easy-to-use components to help developers increase productivity and deliver highest quality applications within time and budget constraints. Our main products are KoolPHP UI and KoolReport. KoolPHP UI is a toolset for developer to construct web faster while KoolReport is an open-source reporting framework to build data reports and dashboard easier.


