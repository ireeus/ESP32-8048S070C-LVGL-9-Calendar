    <!-- Navigation Menu with Filters -->
    <nav class="bg-gradient-to-r from-gray-900 to-gray-800 text-white p-4 sticky top-0 z-20 shadow-lg">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
 <a href="index.php" class="hover:underline text-gray-200 font-medium text-sm"><button type="button" class="bg-green-800 text-white py-1.5 px-3 rounded-md hover:bg-green-900 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">Locate</button></a>

            <form method="get" id="filterForm" class="flex flex-wrap items-center gap-2">
                <div>                             
                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>" class="p-1.5 border border-gray-600 rounded-md text-gray-200 bg-gray-700 focus:ring-2 focus:ring-blue-700 focus:border-blue-700 transition duration-200 text-sm">
                </div>
                <button type="submit" class="bg-green-800 text-white py-1.5 px-3 rounded-md hover:bg-green-900 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">Apply</button>
                <a href="view.php" class="bg-gray-700 text-white py-1.5 px-3 rounded-md hover:bg-gray-800 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">X</a>
                <button type="button" onclick="changeDate(-1)" class="bg-blue-900 text-white py-1.5 px-3 rounded-md hover:bg-blue-950 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">&lt;&lt; </button>
                <button type="button" onclick="changeDate(1)" class="bg-blue-900 text-white py-1.5 px-3 rounded-md hover:bg-blue-950 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm"> &gt;&gt;</button>
                <div class="flex space-x-3">
                </div>
            </form>
        </div>
    </nav>