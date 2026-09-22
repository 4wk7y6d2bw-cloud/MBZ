<?php

unset($_SESSION['admin']);
session_regenerate_id(true);
redirect('./?page=admin');
