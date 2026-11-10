#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
umask 077
if (( $# != 4 )); then
 echo "Usage: $0 <JDK directory> <JSignPdf lib directory> <TSA URL> <TSA trust PEM>" >&2
 exit 2
fi
jdk=$(realpath "$1")
lib=$(realpath "$2")
tsa=$3
tsa_ca=$(realpath "$4")
base=$(cd "$(dirname "$0")" && pwd)
helper="$base/../../resources/pades-report.jar"
tmp=$(mktemp -d)
cd "$tmp"
mkdir public newcerts old new
touch index.txt
printf '1000\n' > serial
# Generate a two-revision synthetic fixture, never use a real document.
python3 - <<'PY'
from pathlib import Path
objs=[b'<< /Type /Catalog /Pages 2 0 R >>',b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>',b'<< /Length 0 >>\nstream\nendstream']
out=b'%PDF-1.7\n'; offsets=[]
for i,o in enumerate(objs,1):
 offsets.append(len(out));out+=f'{i} 0 obj\n'.encode()+o+b'\nendobj\n'
start=len(out);out+=b'xref\n0 5\n0000000000 65535 f \n'+b''.join(f'{p:010} 00000 n \n'.encode() for p in offsets)+f'trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n{start}\n%%EOF\n'.encode()
Path('qa.pdf').write_bytes(out)
PY
cat > ca.cnf <<'EOF'
[ca]
default_ca=local
[local]
database=index.txt
serial=serial
new_certs_dir=newcerts
certificate=ca.pem
private_key=ca.key
default_md=sha256
default_days=3
default_crl_days=1
policy=policy
x509_extensions=leaf
[policy]
commonName=supplied
[leaf]
basicConstraints=critical,CA:false
keyUsage=critical,digitalSignature,nonRepudiation
authorityKeyIdentifier=keyid,issuer
subjectKeyIdentifier=hash
crlDistributionPoints=URI:http://127.0.0.1:38179/qa.crl
EOF
openssl req -new -newkey rsa:2048 -nodes -x509 -days 7 -subj '/CN=Synthetic LT issuer' -addext 'basicConstraints=critical,CA:true' -addext 'keyUsage=critical,keyCertSign,cRLSign' -keyout ca.key -out ca.pem 2>openssl.log
openssl req -new -newkey rsa:2048 -nodes -subj '/CN=Synthetic LibreSign signer' -keyout new.key -out new.csr 2>>openssl.log
openssl ca -batch -config ca.cnf -in new.csr -out new.pem 2>>openssl.log
sleep 1
openssl ca -batch -config ca.cnf -gencrl -out revocation.pem 2>>openssl.log
openssl crl -in revocation.pem -outform DER -out public/qa.crl
openssl pkcs12 -export -inkey new.key -in new.pem -certfile ca.pem -out new.p12 -passout pass:synthetic-only
openssl req -new -newkey rsa:2048 -nodes -x509 -days 3 -subj '/CN=Synthetic external signer' -keyout old.key -out old.pem 2>>openssl.log
openssl pkcs12 -export -inkey old.key -in old.pem -out old.p12 -passout pass:synthetic-only
python3 -m http.server 38179 --bind 127.0.0.1 --directory public >http.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true; printf "Private QA evidence: %s\n" "$tmp"' EXIT
trust="$tmp/ca.pem,$tsa_ca"
j(){ "$jdk/bin/java" -Xmx512m -Djava.awt.headless=true -Duser.home="$tmp" -cp "$lib/*" com.intoolswetrust.jsignpdf.Bootstrap "$@"; }
verify(){ "$jdk/bin/java" -Xmx512m -Djava.awt.headless=true -cp "$helper:$lib/*" coop.libresign.PadesReport "$1" "$trust" 2>"$2" ; }
signflags=(-kst PKCS12 -ksp synthetic-only)
j --engine openpdf "${signflags[@]}" -ksf old.p12 -d old qa.pdf >old.log 2>&1 || { tail -25 old.log; exit 1; }
oldpdf=$(find old -name '*.pdf' -print -quit)
test -s "$oldpdf" || { tail -25 old.log; exit 1; }
verify "$oldpdf" old-report.log >old.tsv
j --engine dss --pades-level T --option engine.dss.online.enabled=false --option "engine.dss.trust.certFiles=$trust" --option engine.dss.trust.allowUntrusted=false --option engine.dss.tsa.nonce=true -ts "$tsa" -a -V -llx 70 -lly 100 -urx 240 -ury 145 "${signflags[@]}" -ksf new.p12 -d new "$oldpdf" >new.log 2>&1 || { tail -35 new.log; exit 1; }
newpdf=$(find new -name '*.pdf' -print -quit)
test -s "$newpdf" || { tail -35 new.log; exit 1; }
verify "$newpdf" new-report.log >new.tsv
certid=C-$(openssl x509 -in new.pem -noout -fingerprint -sha256 | cut -d= -f2 | tr -d :)
sig=$(awk -F '\t' -v cert="$certid" '$1=="INTEGRITY"&&$4==cert{print $2}' new.tsv)
test -n "$sig" || { cat old.tsv new.tsv; exit 1; }
"$jdk/bin/java" -Xmx512m -Djava.awt.headless=true -cp "$helper:$lib/*" coop.libresign.PadesLtAugment "$newpdf" final.pdf "$trust" "$sig" >augment.log 2>&1 || { tail -40 augment.log; exit 1; }
verify final.pdf final-report.log >final.tsv
printf 'QA directory: %s\n' "$tmp"
cmp -n "$(stat -c %s "$oldpdf")" "$oldpdf" final.pdf
cmp -n "$(stat -c %s "$newpdf")" "$newpdf" final.pdf
cat old.tsv new.tsv final.tsv
awk -F '\t' -v sig="$sig" '$1=="SIGNATURE"&&$2==sig&&$4=="PAdES_BASELINE_LT"&&$5=="TOTAL_PASSED"&&$7==1{verified=1} END{exit !verified}' final.tsv
php "$base/test-countersigning-fixture.php" "$tmp" "$oldpdf" "$newpdf"
echo 'PASS: source bytes preserved; new signature passed offline PAdES-LT checks'
