php.ini file updated with:
    post_max_size = 1000M
    upload_max_filesize = 1000M


SELECT sum(amount) FROM `financialtran` where Entrymode = 0 AND crdr='D';
SELECT sum(amount) FROM `financialtran` where Entrymode = 15 AND crdr='C' and Typeofconcession = 1;
SELECT sum(amount) FROM `financialtran` where Entrymode = 15 AND crdr='C' and Typeofconcession = 2;

