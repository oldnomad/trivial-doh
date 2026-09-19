#!/usr/bin/perl -w
use strict;
use Net::DNS::Packet;

my $name  = $ARGV[0] || 'www.example.com';
my $type  = $ARGV[1] || 'A';
my $class = $ARGV[2] || 'IN';

my $packet = new Net::DNS::Packet($name, $type, $class);

my $data = $packet->data;
$data = "\000\000\001".substr($data, 3); # ID = 0, RD = 1
syswrite STDOUT, $data;
