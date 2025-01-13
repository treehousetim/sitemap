# sitemap
A Zero Dependency PHP Sitemap/RSS parser to extract keyword data from a website


## Usage

### Domain Name
`php src/sitemap.php example.com`


### Sitemap xml
`php src/sitemap.php example.com/sitemap.xml`

### Output

`--output=words`

This will output one word per line with the following format:

```csv
6,service
6,area
1,home
1,booking
1,page
1,see
1,our
1,services
```


`--output=slugs`

This will output each separate page slug, one per line.