
import requests
import oauth2
import base64
import hmac
import hashlib
import json
from flask import Flask,redirect

class Consumer:
    def __init__(self, key, secret):
            self.key = key
            self.secret = secret

class Token:
      def __init__(self, key, secret):
            self.key = key
            self.secret = secret


app = Flask(__name__)

@app.route("/tube/<name>")
def tube(name):
    print(name)
    print("https://youtube.com/@"+name+"/live")
    tube_ch = requests.get("https://youtube.com/@"+name+"/live")
    data = tube_ch.text
    tube_url = data[data.index('hlsManifestUrl":"https://manif')+17:data.index('.m3u8')+5]
    #print(tube_url[:40])
    #print(tube_url[:-10])
    #tube_url = tube_url.replace('https://manifest.googlevideo.com','http://20.84.80.199')
    print(tube_url[:20])
    print(tube_url[len(tube_url)-20:])
    return redirect(tube_url)

@app.route("/alkawthar.m3u8")
def hello_world():
    x = requests.get('https://sepehrtv.ir/frame/t/alkosar')
    data = x.text
    data  = data[data.index("_app"):data.index("_app") +16+8]
    x = requests.get('https://sepehrtv.ir/_next/static/chunks/pages/'+data)
    data = x.text
    data = data[data.index("{consumer:{key:"):]
    key = data[16:32+16]
    secret = data[data.index("secret:")+8:data.index("secret:")+8+32]
    token = data[data.index('getItem("secret")')+20:]
    tokenkey = token[token.index('"')+ 1 :token.index('"')+ 65]
    tokensecret = token[token.index('getItem("token")')+20:]
    tokensecret = tokensecret[tokensecret.index('"')+ 1 :tokensecret.index('"')+ 65]
    url = "https://sepehrapi.sepehrtv.ir/v3/channels/?key=alkosar&include_media_resources=true&include_details=true"
    parameters = {
            "oauth_consumer_key": key,
            "oauth_nonce": oauth2.generate_nonce(),
            "oauth_timestamp": oauth2.generate_timestamp(),
            "oauth_signature_method": "HMAC-SHA1",
            "oauth_version": "1.0",
            }
    tokenC = Token(tokenkey,tokensecret)

    consumer = Consumer(key, secret)
    params = {
            "oauth_version": "1.0",
            "oauth_nonce": oauth2.generate_nonce(),
            "oauth_timestamp": str(oauth2.generate_timestamp()),
            "oauth_token": tokenC.key,
            "oauth_consumer_key": consumer.key,
            "oauth_signature_method":"HMAC-SHA1"
            }
    req = oauth2.Request(method="GET", url=url, parameters=params)
    signature_method = oauth2.SignatureMethod_HMAC_SHA1()
    req.sign_request(signature_method, consumer, tokenC)
    headers = req.to_header()
    payload = {}
    response = requests.request("GET", url, headers=headers)

    lista = json.loads(response.text)
    channel = lista['list'][0]['streams'][0]['src']
    fileData = requests.get(channel)
    channel = channel.replace('lb-cdn','s3-cdn').replace('alkawtharsd.m3u8','576p.m3u8')
    print(channel.replace('s3-cdn.sepehrtv.ir','20.84.80.199'))
    return redirect(channel.replace('https://s3-cdn.sepehrtv.ir','http://20.84.80.199'))
    return fileData.text.replace('\n','<br>').replace('576p.m3u8',channel[:channel.index('alkawtharsd.m')] + '576p.m3u8')


if __name__ == "__main__":
    app.run(debug=True, host='0.0.0.0',port=3005)
