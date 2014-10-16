# EBANX payment gateway for VirtueMart/Joomla

This plugin allows you to integrate your VirtueMart store with the EBANX payment gateway.
It supports all EBANX payment methods available on our Checkout Integration.

## Requirements

* PHP >= 5.3
* cURL
* VirtueMart >= 2.6

## Installation
### Source
1. Clone the git repo to your VirtueMart _/plugins/vmpayment/_ folder
```
git clone --recursive https://github.com/ebanx/ebanx-virtuemart.git
```
2. Go to the VirtueMart administration and then to the payment settings at **VirtueMart > Payment Methods**.
3. Create the EBANX payment method and update its settings.
4. Go to the EBANX Merchant Area, then to **Integration > Merchant Options**.
  1. Change the _Status Change Notification URL_ to:
```
{YOUR_SITE}/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification
```
  2. Change the _Response URL_ to:
```
{YOUR_SITE}/index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived
```
5. That's all!

### Extension manager
1. Download the [module ZIP file](http://downloads.ebanx.com/ebanx.virtuemart.latest.zip).
2. Go to the VirtueMart administration and then to the payment settings at **Extensions > Extension Manager**.
3. Upload the EBANX package file.
4. Go to the VirtueMart administration and then to the payment settings at **VirtueMart > Payment Methods**.
5. Create the EBANX payment method and update its settings.
6. Go to the EBANX Merchant Area, then to **Integration > Merchant Options**.
  1. Change the _Status Change Notification URL_ to:
```
{YOUR_SITE}/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification
```
  2. Change the _Response URL_ to:
```
{YOUR_SITE}/index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived
```
7. That's all!

## Changelog
* 1.0.0: first release