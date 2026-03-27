# session_ratsinformation_documents_to_rss
Extracts the latest documents of the somacos session app / sessionnet to a feed in FreshRSS (HTML + XPath)

Latest documents in session app by the City of Duisburg [https://sessionnet.owl-it.de/duisburg/bi/do0040.asp](https://sessionnet.owl-it.de/duisburg/bi/do0040.asp)

## Add Feed to FreshRSS with the type of feed source: HTML + XPath

Feed URL: `https://sessionnet.owl-it.de/duisburg/bi/do0040.asp`  
feed title: `"Ratsinformation Duisburg"`  
finding news items: `//tr[@class="smc-t-r-l"]`  
item title: `substring-after(descendant::td[contains(@class,"dovorgang")]/a/@title, ": ")`  
item content: `descendant::td[@class="smc-t-cl991 xxdocs"]`  
item link (URL): `descendant::td[@class="smc-t-cl991 dovorgang"]/a/@href`  
item date: `descendant::ul[contains(@class,"smc-detail-list")]/li[1]`  
item tags: `descendant::td[@class="smc-t-cl991 doart"]`  
