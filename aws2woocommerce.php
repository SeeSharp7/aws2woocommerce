<?
//////////////////////////////////////////////////////////
//CreateDate:   25.11.2016
//Author:       René Fehlow, @SeeSharp7
//Description:  This job imports all products via its ASIN
//              from amazon aws (product advertisement api) 
//              to a wordpress woocommerce shop
//////////////////////////////////////////////////////////
//To Do:        Update images and price of existing products
//To Do:        Update products dimensions (given in inches)
//////////////////////////////////////////////////////////

//First at all: Is DEBUG-OUTPUT enabled?
$debug_output = true;
$verbose_woocommerce_traffic = false;
$verbose_amazonwebsvc_traffic = false;

//What do you want to import?
//Amazon Product ASINs
$amazon_product_asin_collection = array("B00X4FAQSE","B01GQ85GW6");

//WooCommerce API (WooCommerce->Settings->API->Keys/Apps)
$wc_consumer_key =      "YOUR_WC_CONSUMER_KEY";
$wc_consumer_secret =   "YOUR_WC_CONSUMER_SECRET";
$wc_api_url =           "https://YOURDOMAIN.com/wp-json/wc/v1/";

//Amazon Product Advertising API
$aws_access_key_id =    "YOUR_AWS_ACCESS_KEY_ID";
$aws_secret_key =       "YOUR_AWS_SECRET_KEY";
$aws_associate_tag =    "YOUR_PARTNER_ID"; //that thing ending with -21
$aws_endpoint =         "webservices.amazon.de";
$aws_uri =              "/onca/xml";

//////////////////////////////////////////////////////////
//Iterate through products and get information from aws
//////////////////////////////////////////////////////////

//Make array of ASINs unique
$amazon_product_asin_collection_unique = array_unique($amazon_product_asin_collection);

if ($debug_output) {
    echo "INJECTED PRODUCT COUNT: ".count($amazon_product_asin_collection)."</br>";
    echo "UNIQUE PRODUCT COUNT: ".count($amazon_product_asin_collection_unique)."</br>";
    //print_r($amazon_product_asin_collection_unique);
    echo "</br>";
}

//Iterate through product asins
foreach ($amazon_product_asin_collection_unique as $amazon_product_asin) {
    
    //Skip empty product asins
    if ($amazon_product_asin == "") {
        if ($debug_output) {
            echo "Skipped empty ASIN</br>";
        }

        continue;
    }

    //Sleep 2 seconds (otherwise amazon may throttle your requests)
    sleep(2);

    //Further aws parameters
    $aws_params = array(
        "ItemId" =>         $amazon_product_asin,
        "AWSAccessKeyId" => $aws_access_key_id,
        "AssociateTag" =>   $aws_associate_tag,
        "Service" =>        "AWSECommerceService",
        "ResponseGroup" =>  "Images,ItemAttributes,Offers",
        "Operation" =>      "ItemLookup",
        "IdType" =>         "ASIN"
    );

    //Set current timestamp if not set
    if (!isset($aws_params["Timestamp"])) {
        $aws_params["Timestamp"] = gmdate('Y-m-d\TH:i:s\Z');
    }

    //Sort the parameters by key
    ksort($aws_params);
    $pairs = array();

    foreach ($aws_params as $key => $value) {
        array_push($pairs, rawurlencode($key)."=".rawurlencode($value));
    }

    //Generate the canonical query
    $canonical_query_string = join("&", $pairs);

    //Generate the string to be signed
    $string_to_sign = "GET\n".$aws_endpoint."\n".$aws_uri."\n".$canonical_query_string;

    //Generate the signature required by the Product Advertising API
    $signature = base64_encode(hash_hmac("sha256", $string_to_sign, $aws_secret_key, true));

    //Generate the signed URL
    $aws_request_url = 'http://'.$aws_endpoint.$aws_uri.'?'.$canonical_query_string.'&Signature='.rawurlencode($signature);

    if ($verbose_amazonwebsvc_traffic) {
        echo "Calling Amazon Webservice-URL: ".$aws_request_url."</br>";
    }

    //Initialize curl handle
    $aws_curl = curl_init();
    curl_setopt($aws_curl, CURLOPT_URL, $aws_request_url);
    curl_setopt($aws_curl, CURLOPT_RETURNTRANSFER, 1);

    //Execute aws request via curl
    $aws_products_xml = curl_exec($aws_curl);
    curl_close($aws_curl);

    //Verbose information
    if ($verbose_amazonwebsvc_traffic) {
        echo "AWS-RESPONSE: </br>";
        print_r($aws_products_xml);
        echo "</<br>";
    }

    //Decode xml to object
    $aws_products = simplexml_load_string($aws_products_xml);

    //It returns only one product, so directly map it
    $product = $aws_products->Items->Item[0];

    //////////////////////////////////////////////////////////
    //Extract images
    //////////////////////////////////////////////////////////
    
    //Build image array
    $counter = 1;
    $images = array();

    //This is the primary list image
    $images[] = array(
        'src' => (string)$product->LargeImage->URL[0], 
        'position' => 0
    );

    //And here all the other images
    foreach ($product->ImageSets->ImageSet as $imageset) {
        $item = array(
            'src' => (string)$imageset->LargeImage->URL[0], 
            'position' => $counter
        );
        $images[] = $item;
        $counter++;
    }

    //////////////////////////////////////////////////////////
    //Extract description
    //////////////////////////////////////////////////////////

    $product_description = "<h1>".$product->ItemAttributes->Title."</h1></br>";
    $product_description .= "<h2>Features</h2><br/>";
    
    $product_description .= "<ul>";
    foreach ($product->ItemAttributes->Feature as $feature) {
        $product_description .= "<li><span class=\"a-list-item\">".$feature."</span></li>";
    }
    $product_description .= "</ul>";

    //////////////////////////////////////////////////////////
    //Extract prices
    //////////////////////////////////////////////////////////

    $aws_regular_price = (float)((int)$product->ItemAttributes->ListPrice->Amount / 100); 
    $aws_current_price = (float)((int)$product->OfferSummary->LowestNewPrice->Amount[0] / 100); 

    if ($aws_regular_price == 0) {
        $aws_regular_price = $aws_current_price;
        $aws_current_price = null;
    }

    $price_info = [
        'sale_price' => (string)$aws_current_price,
        'regular_price' => (string)$aws_regular_price
    ];

    //$dimensions = $product->ItemAttributes->ItemDimensions;

    //////////////////////////////////////////////////////////
    //All the other product data
    //////////////////////////////////////////////////////////

    if ($debug_output) {
        echo "Enterting INSERT section</br>";
    }

    //Build full product
    $data = [
        'name' => (string)$product->ItemAttributes->Title[0],
        'status' => 'publish', //'draft' for tests, trust me
        'sku' => (string)$product->ASIN[0],//used for update prices job
        'type' => 'external',//means affiliate
        'button_text' => 'Bei Amazon ansehen',
        'external_url' => urldecode($product->DetailPageURL[0]), //is already branded as partner link
        'description' => $product_description,
        /*'dimensions' => {
            "length" => (string)(($dimensions->Length / 100) * 2.54),
            "width" => (string)(($dimensions->Width / 100) * 2.54),
            "height" => (string)(($dimensions->Height / 100) * 2.54) },*/
        'images' => $images
    ];

    $final_product_data = array_merge($data, $price_info);
    
    //////////////////////////////////////////////////////////
    //Insert or update WooCommerce product
    //////////////////////////////////////////////////////////

    //Build request url (key/secret as parameter -> all-inkl does not process authorization header correctly -.-)
    $wc_insert_product_url = $wc_api_url . "products";
    $wc_insert_product_url .= "?consumer_key=" . $wc_consumer_key;
    $wc_insert_product_url .= "&consumer_secret=" . $wc_consumer_secret;

    //Request data
    $header = "Content-Type: application/json";

    //Initialize curl handle
    $wc_insert_curl = curl_init();
    curl_setopt($wc_insert_curl, CURLOPT_URL, $wc_insert_product_url);
    curl_setopt($wc_insert_curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($wc_insert_curl, CURLOPT_HTTPHEADER, array($header));
    curl_setopt($wc_insert_curl, CURLOPT_POSTFIELDS, json_encode($final_product_data)); 
    curl_setopt($wc_insert_curl, CURLOPT_RETURNTRANSFER, 1);

    //Execute api update request via curl
    $response = curl_exec($wc_insert_curl);
    
    //Verbose information
    if ($verbose_woocommerce_traffic) {
        echo "WC REST API INSERT RESPONSE: ".$response."</br>";
    }

    $info = curl_getinfo($wc_insert_curl);
    curl_close($wc_insert_curl);

    if (isset($info['http_code']) && $info['http_code'] == 201) {
        echo "SUCCESS: Imported product asin: ".$amazon_product_asin."<br/>";
    }
    else if (isset($info['http_code']) && $info['http_code'] == 400) //Error
    { 
        if ($debug_output) {
                echo "ERROR: Theres an error in the request or the product already exists <br/>";
        }
        continue;//CHANGE THIS!        

        if ($debug_output) {
            echo "Enterting UPDATE section</br>";
        }

        //Build update product
        $data = [
            'regular_price' => (string)$aws_product_price,
            'images' => $images
        ];

        //Build request url (key/secret as parameter -> all-inkl does not process authorization header correctly -.-)
        $wc_update_product_url = $wc_api_url . "products?sku=" . $amazon_product_asin;
        $wc_update_product_url = $wc_update_product_url . "?consumer_key=" . $wc_consumer_key;
        $wc_update_product_url = $wc_update_product_url . "&consumer_secret=" . $wc_consumer_secret;

        if ($debug_output) {
            echo "WC Update URL: ".$wc_update_product_url."</br>";
        }

        //Request data
        $header = "Content-Type: application/json";

        //Initialize curl handle
        $wc_update_curl = curl_init();
        curl_setopt($wc_update_curl, CURLOPT_URL, $wc_update_product_url);
        curl_setopt($wc_update_curl, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($wc_update_curl, CURLOPT_HTTPHEADER, array($header));
        curl_setopt($wc_update_curl, CURLOPT_POSTFIELDS, json_encode($data)); 
        curl_setopt($wc_update_curl, CURLOPT_RETURNTRANSFER, 1);

        //Execute api update request via curl
        $response = curl_exec($wc_update_curl); //debugging: 
        
        if ($verbose_woocommerce_traffic) {
            echo "WC REST API UPDATE RESPONSE: ".$response."</br>";
        }
        
        $info = curl_getinfo($wc_update_curl);
        curl_close($wc_update_curl);

        if (isset($info['http_code']) && $info['http_code'] == 200) {
            echo "SUCCESS: Update product (id: ".$amazon_product_asin.") price: ".$product_price." changed to ".$aws_product_price."<br/>";
        }
        else {
            echo "ERROR: Update for product ".$amazon_product_asin." returned ".$info['http_code']."<br/>";
        }
    }
}

echo "FINISHED";
?>
